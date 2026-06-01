<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
use App\Models\KYCVerification;
use App\Models\User;
use App\Services\UploadService;
use Core\Database;
use Core\EventDispatcher;
use Core\RateLimiter;
use Core\IdempotencyKey;
use Core\TransactionWrapper;
use App\Events\KYCApprovedEvent;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;

class KYCService
{
    private KYCVerification  $kycModel;
    private User             $userModel;
    private UploadService    $uploadService;
    private \App\Adapters\KycFaceVerificationAdapter $aiAdapter;
    private \Core\Encryption $encryption;

        private \Core\Database $db;
        private \App\Contracts\LoggerInterface $logger;
        public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Models\KYC $model
    ) {            $this->db = $db;
            $this->logger = $logger;

        $this->model = $model;
    }


    /**
     * بررسی اینکه کاربر می‌تواند KYC ثبت کند یا نه
     */
    public function canSubmitKYC(int $userId): array
    {
        $existingKYC = $this->kycModel->findByUserId($userId);

        if (!$existingKYC) return ['can' => true];

        if ($existingKYC->status === 'verified') {
            return ['can' => false, 'reason' => 'احراز هویت شما قبلاً تأیید شده است'];
        }

        if (in_array($existingKYC->status, ['pending', 'under_review'])) {
            return ['can' => false, 'reason' => 'درخواست قبلی شما در حال بررسی است'];
        }

        if ($existingKYC->status === 'rejected') {
            $daysSinceRejection = (time() - strtotime($existingKYC->reviewed_at)) / 86400;
            if ($daysSinceRejection < 7) {
                return ['can' => false, 'reason' => 'شما باید ' . ceil(7 - $daysSinceRejection) . ' روز دیگر صبر کنید'];
            }
        }

        return ['can' => true];
    }

    /**
     * تشخیص Photoshop ساده
     */
    public function detectPhotoshop(string $imagePath): array
    {
        $suspicious = false;
        $reasons    = [];

        $exif = @exif_read_data($imagePath);
        if ($exif) {
            if (isset($exif['Software'])) {
                $software = strtolower($exif['Software']);
                if (strpos($software, 'photoshop') !== false || strpos($software, 'gimp') !== false) {
                    $suspicious = true;
                    $reasons[]  = 'تصویر با نرم‌افزار ویرایش ساخته شده';
                }
            }

            if (isset($exif['DateTime']) && isset($exif['DateTimeOriginal'])) {
                $diff = abs(strtotime($exif['DateTime']) - strtotime($exif['DateTimeOriginal']));
                if ($diff > 60) {
                    $suspicious = true;
                    $reasons[]  = 'اختلاف زمانی مشکوک بین ساخت و ویرایش';
                }
            }
        }

        if ($suspicious) {
            $this->logger->warning('kyc.image.suspicious', [
                'channel' => 'kyc',
                'image_path' => basename($imagePath),
                'reasons' => $reasons,
                'software' => $exif['Software'] ?? null
            ]);
        }

        return ['suspicious' => $suspicious, 'reasons' => $reasons];
    }

    /**
     * ثبت KYC با یک فایل
     */
    public function submitKYC(int $userId, array $data, array $files): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\KYC\SubmitKYCJob::class);
        return $job->handle($userId, $data, $files);
    }
    /**
     * تأیید KYC توسط ادمین
     */
    public function verifyKYC(int $kycId, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\KYC\VerifyKYCJob::class);
        return $job->handle($kycId, $adminId);
    }
    /**
     * رد KYC توسط ادمین
     */
    public function rejectKYC(int $kycId, int $adminId, string $reason): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\KYC\RejectKYCJob::class);
        return $job->handle($kycId, $adminId, $reason);
    }
    /**
     * دریافت تمامی رکوردهای احراز هویت (برای ادمین)
     */
    public function getAll(SearchQuery $query, bool $maskPII = false): SearchResult
    {
        $filters = $query->getFilters();
        if ($query->getTerm()) {
            $filters['q'] = $query->getTerm();
        }
        $filters['sort'] = $query->getSort();

        $results = $this->kycModel->getAll($filters, $query->getLimit(), $query->getOffset());
        $total = $this->count($filters);

        foreach ($results as $kyc) {
            if (!empty($kyc->national_code)) {
                $decrypted = $this->encryption->decrypt((string)$kyc->national_code);
                $kyc->national_code = $maskPII
                    ? (strlen($decrypted) >= 5 ? substr($decrypted, 0, 3) . '****' . substr($decrypted, -2) : '*****')
                    : $decrypted;
            }
            if (!empty($kyc->birth_date)) {
                $decrypted = $this->encryption->decrypt((string)$kyc->birth_date);
                $kyc->birth_date = $maskPII
                    ? (strlen($decrypted) >= 4 ? substr($decrypted, 0, 4) . '/**/**' : '**//**')
                    : $decrypted;
            }
        }
        return new SearchResult($results, $total);
    }

    /**
     * شمارش رکوردهای احراز هویت بر اساس فیلتر
     */
    public function count(array $filters = []): int
    {
        return $this->kycModel->count($filters);
    }

    /**
     * یافتن رکورد خاص
     */
    public function find(int $id, bool $maskPII = false): ?object
    {
        $kyc = $this->kycModel->find($id);
        if ($kyc && !empty($kyc->national_code)) {
            $decrypted = $this->encryption->decrypt((string)$kyc->national_code);
            $kyc->national_code = $maskPII
                ? (strlen($decrypted) >= 5 ? substr($decrypted, 0, 3) . '****' . substr($decrypted, -2) : '*****')
                : $decrypted;
        }
        if ($kyc && !empty($kyc->birth_date)) {
            $decrypted = $this->encryption->decrypt((string)$kyc->birth_date);
            $kyc->birth_date = $maskPII
                ? (strlen($decrypted) >= 4 ? substr($decrypted, 0, 4) . '/**/**' : '**//**')
                : $decrypted;
        }
        return $kyc;
    }

    /**
     * ✅ دریافت آمار وضعیت‌ها با یک کوئری GROUP BY
     * به جای 4 کوئری جداگانه
     */
    public function getStatsByStatus(): array
    {
        $stats = $this->db->connection()
            ->table('kyc_verifications')
            ->selectRaw('status, COUNT(*) as count')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->get();

        $result = [
            'pending' => 0,
            'under_review' => 0,
            'verified' => 0,
            'rejected' => 0,
        ];

        if (is_array($stats)) {
            foreach ($stats as $stat) {
                $status = $stat['status'] ?? ($stat->status ?? null);
                $count = $stat['count'] ?? ($stat->count ?? 0);
                if (isset($result[$status])) {
                    $result[$status] = (int)$count;
                }
            }
        }

        return $result;
    }

    /**
     * حذف فیزیکی تصویر احراز هویت و تغییر وضعیت دیتابیس به وضعیت پاک‌شده
     */
    public function deleteVerificationImage(int $id): bool
    {
        $kyc = $this->kycModel->find($id);
        if (!$kyc) return false;

        $file = (string)($kyc->verification_image ?? '');
        if ($file !== '' && $file !== '[DELETED]') {
            $path = \str_contains($file, '/') ? $file : ('kyc/' . $file);
            try {
                if ($this->uploadService) {
                    $this->uploadService->delete($path);
                }
            } catch (\Throwable $e) {
                $this->logger->error('kyc.delete_image.failed', [
                    'channel' => 'kyc',
                    'kyc_id' => $id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $this->kycModel->updateImageStatusToDeleted($id);
    }
}
