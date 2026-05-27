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
use App\Events\KYCApprovedEvent;
use App\Services\Search\SearchQuery;
use App\Services\Search\SearchResult;

class KYCService extends \App\Services\BaseService
{
    private KYCVerification  $kycModel;
    private User             $userModel;
    private UploadService    $uploadService;
    private \App\Adapters\KycFaceVerificationAdapter $aiAdapter;
    private \Core\Encryption $encryption;

    public function __construct(
        KYCVerification      $kycModel,
        User                 $userModel,
        Database             $db,
        UploadService        $uploadService,
        \App\Adapters\KycFaceVerificationAdapter $aiAdapter,
        LoggerInterface      $logger,
        \Core\Encryption     $encryption,
        EventDispatcher      $eventDispatcher,
        private RateLimiter  $rateLimiter,
        private IdempotencyKey $idempotency
    ) {
        // انتقال زیرساخت به BaseService
        parent::__construct($logger, null, $db, null, null, null, null, $eventDispatcher);
        $this->kycModel            = $kycModel;
        $this->userModel           = $userModel;
        $this->uploadService       = $uploadService;
        $this->aiAdapter           = $aiAdapter;
        $this->encryption          = $encryption;
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
    $uploadResult = null;

    // 🛡️ Idempotency Check: Prevent duplicate KYC submissions
    $ikey = $data['idempotency_key'] ?? null;
    if ($ikey) {
        $cached = $this->idempotency->check($ikey, "kyc_submit:{$userId}");
        if ($cached) return $cached;
    }

    // 🛡️ Service-Layer Rate Limiting: Prevent automated/spam KYC submissions
    if (!$this->rateLimiter->attempt("kyc_submit:{$userId}", 2, 3600)) {
        $this->logger->warning('kyc.submit.rate_limited', ['user_id' => $userId]);
        return $this->idempotency->save($ikey, [
            'success' => false, 'message' => 'تعداد تلاش‌های شما برای احراز هویت بیش از حد مجاز است. لطفاً یک ساعت دیگر تلاش کنید.'
        ], 3600);
    }

    try {
        // 1) ورودی پایه
        if (empty($files['verification_image'])) {
            return ['success' => false, 'message' => 'تصویر احراز هویت الزامی است'];
        }

        $canSubmit = $this->canSubmitKYC($userId);
        if (!$canSubmit['can']) {
            return ['success' => false, 'message' => $canSubmit['reason']];
        }

        $nationalCode = trim((string)($data['national_code'] ?? ''));
        if ($nationalCode !== '' && !preg_match('/^\d{10}$/', $nationalCode)) {
            return ['success' => false, 'message' => 'کد ملی نامعتبر است'];
        }

        if ($nationalCode !== '') {
            $stmt = $this->db->query("SELECT user_id, national_code FROM kyc_verifications WHERE status = 'verified'");
            while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
                if (!empty($row->national_code)) {
                    try {
                        $decrypted = $this->encryption->decrypt((string)$row->national_code);
                        if ($decrypted === $nationalCode && (int)$row->user_id !== $userId) {
                            return [
                                'success' => false,
                                'message' => 'این کد ملی قبلاً در سیستم ثبت شده است'
                            ];
                        }
                    } catch (\Throwable $ignore) {}
                }
            }
        }

        // 2) آپلود فایل
        $uploadResult = $this->uploadService->upload($files['verification_image'], 'kyc');
        if (empty($uploadResult['success'])) {
            return ['success' => false, 'message' => $uploadResult['message'] ?? 'خطا در آپلود تصویر'];
        }

        $filename = (string)$uploadResult['filename'];

        // 3) بررسی فتوشاپ/ریسک
        $uploadPath = $this->uploadService->getPath('kyc/' . $filename);
        $photoshopCheck = $uploadPath ? $this->detectPhotoshop($uploadPath) : ['suspicious' => false, 'reasons' => []];

        // --- U-4: بررسی هوش مصنوعی (اگر فعال باشد) ---
        $aiCheck = ['is_valid' => true, 'confidence' => 1.0];
        if ($this->aiAdapter->isConfigured() && $uploadPath) {
            $aiAnalysis = $this->aiAdapter->analyzeImage($uploadPath);
            if ($aiAnalysis['success']) {
                $aiCheck = $aiAnalysis;
                if (!$aiCheck['is_valid']) {
                    $photoshopCheck['suspicious'] = true; // پرچم‌گذاری به عنوان مشکوک جهت بررسی اپراتور
                    $photoshopCheck['reasons'][] = 'رد شدن توسط هوش مصنوعی: ' . ($aiCheck['ai_notes'] ?? 'عدم تأیید تصویر');
                }
            } else {
                // Downstream AI Service Failure -> FAIL-SECURE! Force manual review.
                $photoshopCheck['suspicious'] = true;
                $photoshopCheck['reasons'][] = 'عدم امکان تأیید هوکار خودکار (خطای سرویس هوش مصنوعی). جهت بررسی دستی ارجاع شد.';
                $this->logger->warning('kyc.ai_check.failed_secure', [
                    'user_id' => $userId,
                    'error' => $aiAnalysis['message'] ?? 'Unknown AI service error'
                ]);
            }
        }

        // 4) ثبت اتمیک
        $this->db->beginTransaction();

        $kycId = $this->kycModel->create([
            'user_id'            => $userId,
            'verification_image' => $filename,
            'national_code'      => $nationalCode !== '' ? $this->encryption->encrypt($nationalCode) : null,
            'birth_date'         => !empty($data['birth_date']) ? $this->encryption->encrypt((string)$data['birth_date']) : null,
            'status'             => !empty($photoshopCheck['suspicious']) ? 'under_review' : 'pending',
            'ip_address'         => get_client_ip(),
            'user_agent'         => get_user_agent(),
            'device_fingerprint' => generate_device_fingerprint(),
        ]);

        if (!$kycId) {
            $this->db->rollBack();
            $this->uploadService->delete('kyc/' . $filename);
            $this->logger->error('kyc.user_status_update.failed', [
                'channel' => 'kyc',
                'user_id' => $userId,
                'kyc_id' => $kycId,
            ]);
            return ['success' => false, 'message' => 'خطا در بروزرسانی وضعیت کاربر'];
        }

        $this->db->commit();

        $this->eventDispatcher->dispatchAsync('kyc.status_changed', [
            'kyc_id' => (int)$kycId,
            'user_id' => $userId,
            'old_status' => null,
            'new_status' => !empty($photoshopCheck['suspicious']) ? 'under_review' : 'pending',
            'metadata' => [
                'photoshop_suspicious' => !empty($photoshopCheck['suspicious']),
                'ai_verified' => $aiCheck['is_valid'] ?? true
            ]
        ]);

        return [
            'success' => true,
            'message' => 'درخواست احراز هویت ثبت شد',
            'kyc_id' => (int)$kycId,
        ];
    } catch (\Throwable $e) {
        $this->db->rollBack();

        // اگر فایل آپلود شده اما DB ثبت نشده بود، orphan نماند
        if (!empty($uploadResult['filename'])) {
            $this->uploadService->delete('kyc/' . $uploadResult['filename']);
        }

        $this->logger->critical('kyc.submit.exception', [
            'channel' => 'kyc',
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return ['success' => false, 'message' => 'خطای سیستمی در ثبت احراز هویت'];
    }
}
    /**
     * تأیید KYC توسط ادمین
     */
    public function verifyKYC(int $kycId, int $adminId): array
{
    try {
        $this->db->beginTransaction();

        $kyc = $this->kycModel->findForUpdate($kycId);
        if (!$kyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'درخواست KYC یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست قبلا بررسی شده است'];
        }

        // H-2: concurrency lock check
        if (!empty($kyc->under_review_by) && (int)$kyc->under_review_by !== $adminId) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست توسط ادمین دیگری در حال بررسی است'];
        }

        $okKyc = $this->kycModel->update($kycId, [
            'status' => 'verified',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => null,
            'under_review_by' => null,
            'review_started_at' => null,
        ]);

        if (!$okKyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی KYC'];
        }

        $this->db->commit();

        $this->eventDispatcher->dispatchAsync('kyc.status_changed', [
            'kyc_id' => $kycId,
            'user_id' => (int)$kyc->user_id,
            'old_status' => $kyc->status,
            'new_status' => 'verified',
            'admin_id' => $adminId
        ]);

        // Dispatch class-based approved event for new listeners
        $this->eventDispatcher->dispatchAsync(
            KYCApprovedEvent::class,
            new KYCApprovedEvent((int)$kyc->user_id, $kycId)
        );

        return ['success' => true, 'message' => 'KYC با موفقیت تایید شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();

        $this->logger->critical('kyc.verify.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return ['success' => false, 'message' => 'خطای سیستمی در تایید KYC'];
    }
}
    /**
     * رد KYC توسط ادمین
     */
   public function rejectKYC(int $kycId, int $adminId, string $reason): array
{
    try {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'message' => 'دلیل رد الزامی است'];
        }

        $this->db->beginTransaction();

        $kyc = $this->kycModel->findForUpdate($kycId);
        if (!$kyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'درخواست KYC یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست قبلا بررسی شده است'];
        }

        // H-2: concurrency lock check
        if (!empty($kyc->under_review_by) && (int)$kyc->under_review_by !== $adminId) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست توسط ادمین دیگری در حال بررسی است'];
        }

        $okKyc = $this->kycModel->update($kycId, [
            'status' => 'rejected',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
            'under_review_by' => null,
            'review_started_at' => null,
        ]);

        if (!$okKyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی KYC'];
        }

        $okUser = $this->userModel->update((int)$kyc->user_id, [
            'kyc_status' => 'rejected',
        ]);

        if (!$okUser) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی کاربر'];
        }

        $this->db->commit();

        $this->eventDispatcher->dispatchAsync('kyc.status_changed', [
            'kyc_id' => $kycId,
            'user_id' => (int)$kyc->user_id,
            'old_status' => $kyc->status,
            'new_status' => 'rejected',
            'reason' => $reason,
            'admin_id' => $adminId
        ]);

        return ['success' => true, 'message' => 'KYC با موفقیت رد شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();

        $this->logger->critical('kyc.reject.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => basename($e->getFile()) . ':' . $e->getLine(),
        ]);

        return ['success' => false, 'message' => 'خطای سیستمی در رد KYC'];
    }
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
