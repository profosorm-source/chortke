<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\Notification\NotificationService;
use App\Models\KYCVerification;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\UploadService;
use Core\Database;

class KYCService extends \App\Services\BaseService
{
    private Database         $db;
    private KYCVerification  $kycModel;
    private User             $userModel;
    private UploadService    $uploadService;
    private AuditTrail       $auditTrail;
    private NotificationService $notificationService;
    private \App\Services\Adapters\KycFaceVerificationAdapter $aiAdapter;

    public function __construct(
        KYCVerification      $kycModel,
        User                 $userModel,
        Database             $db,
        UploadService        $uploadService,
        AuditTrail           $auditTrail,
        \App\Services\Adapters\KycFaceVerificationAdapter $aiAdapter,
        LoggerInterface      $logger,
        ?NotificationService $notificationService = null
    ) {
        parent::__construct($logger);
        $this->kycModel            = $kycModel;
        $this->userModel           = $userModel;
        $this->db                  = $db;
        $this->uploadService       = $uploadService;
        $this->auditTrail          = $auditTrail;
        $this->aiAdapter           = $aiAdapter;
        $this->notificationService = $notificationService;
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

    try {
        // 1) ورودی پایه
        if (empty($files['verification_image'])) {
            return ['success' => false, 'message' => 'تصویر احراز هویت الزامی است'];
        }

        $nationalCode = trim((string)($data['national_code'] ?? ''));
        if ($nationalCode !== '' && !preg_match('/^\d{10}$/', $nationalCode)) {
            return ['success' => false, 'message' => 'کد ملی نامعتبر است'];
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
            }
        }

        // 4) ثبت اتمیک
        $this->db->beginTransaction();

        $kycId = $this->kycModel->create([
            'user_id'            => $userId,
            'verification_image' => $filename,
            'national_code'      => $nationalCode !== '' ? $nationalCode : null,
            'birth_date'         => $data['birth_date'] ?? null,
            'status'             => !empty($photoshopCheck['suspicious']) ? 'under_review' : 'pending',
            'ip_address'         => get_client_ip(),
            'user_agent'         => get_user_agent(),
            'device_fingerprint' => generate_device_fingerprint(),
        ]);

        if (!$kycId) {
            $this->db->rollBack();
            $this->uploadService->delete('kyc/' . $filename);
            $this->logger->error('kyc.create.failed', [
                'channel' => 'kyc',
                'user_id' => $userId,
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت درخواست احراز هویت'];
        }

        $okUser = $this->userModel->update($userId, [
            'kyc_status' => 'pending',
        ]);

        if (!$okUser) {
            $this->db->rollBack();
            $this->uploadService->delete('kyc/' . $filename);
            $this->logger->error('kyc.user_status_update.failed', [
                'channel' => 'kyc',
                'user_id' => $userId,
                'kyc_id' => $kycId,
            ]);
            return ['success' => false, 'message' => 'خطا در بروزرسانی وضعیت کاربر'];
        }

        $this->auditTrail->record('kyc.submitted', $userId, [
            'kyc_id' => (int)$kycId,
            'photoshop_suspicious' => !empty($photoshopCheck['suspicious']) ? 1 : 0,
            'ai_verified' => (int)($aiCheck['is_valid'] ?? 1),
            'ai_confidence' => (float)($aiCheck['confidence'] ?? 1.0)
        ], $userId);

        $this->db->commit();

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
            'file' => $e->getFile(),
            'line' => $e->getLine(),
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

        $kyc = $this->kycModel->find($kycId);
        if (!$kyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'درخواست KYC یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست قبلا بررسی شده است'];
        }

        $okKyc = $this->kycModel->update($kycId, [
            'status' => 'verified',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => null,
        ]);

        if (!$okKyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی KYC'];
        }

        $okUser = $this->userModel->update((int)$kyc->user_id, [
            'kyc_status' => 'verified',
        ]);

        if (!$okUser) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در بروزرسانی کاربر'];
        }

        $this->auditTrail->record('kyc.verified', (int)$kyc->user_id, [
            'kyc_id' => $kycId,
        ], $adminId);

        $this->db->commit();

        try {
            if ($this->notificationService && method_exists($this->notificationService, 'sendKYCApproved')) {
    $this->notificationService->sendKYCApproved((int)$kyc->user_id, $kycId);
}
        } catch (\Throwable $e) {
            $this->logger->error('kyc.verify.notification.failed', [
                'channel' => 'kyc',
                'kyc_id' => $kycId,
                'user_id' => (int)$kyc->user_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return ['success' => true, 'message' => 'KYC با موفقیت تایید شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();

        $this->logger->critical('kyc.verify.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
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

        $kyc = $this->kycModel->find($kycId);
        if (!$kyc) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'درخواست KYC یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این درخواست قبلا بررسی شده است'];
        }

        $okKyc = $this->kycModel->update($kycId, [
            'status' => 'rejected',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'rejection_reason' => $reason,
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

        $this->auditTrail->record('kyc.rejected', (int)$kyc->user_id, [
            'kyc_id' => $kycId,
            'reason' => $reason,
        ], $adminId);

        $this->db->commit();

        // نوتیف خارج از transaction
        try {
           if ($this->notificationService && method_exists($this->notificationService, 'sendKYCRejected')) {
    $this->notificationService->sendKYCRejected((int)$kyc->user_id, $kycId, $reason);
}
        } catch (\Throwable $e) {
            $this->logger->error('kyc.reject.notification.failed', [
                'channel' => 'kyc',
                'kyc_id' => $kycId,
                'user_id' => (int)$kyc->user_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return ['success' => true, 'message' => 'KYC با موفقیت رد شد'];
    } catch (\Throwable $e) {
        $this->db->rollBack();

        $this->logger->critical('kyc.reject.exception', [
            'channel' => 'kyc',
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return ['success' => false, 'message' => 'خطای سیستمی در رد KYC'];
    }
}

    /**
     * دریافت تمامی رکوردهای احراز هویت (برای ادمین)
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->kycModel->getAll($filters, $limit, $offset);
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
    public function find(int $id): ?object
    {
        return $this->kycModel->find($id);
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
}

