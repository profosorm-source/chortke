<?php

declare(strict_types=1);

namespace App\Jobs\KYC;

class SubmitKYCJob
{
    private \App\Contracts\LoggerInterface $logger;
    private \Core\Database $db;
    private \App\Services\UploadService $uploadService;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \Core\Database $db,
        \App\Services\UploadService $uploadService
    ) {        $this->logger = $logger;
        $this->db = $db;
        $this->uploadService = $uploadService;
}

public function handle(int $userId, array $data, array $files): array
{
    $uploadResult = null;

    $ikey = $data['idempotency_key'] ?? null;

    // 🛡️ Service-Layer Rate Limiting: Prevent automated/spam KYC submissions
    if (!$this->rateLimiter->attempt("kyc_submit:{$userId}", 2, 3600)) {
        $this->logger->warning('kyc.submit.rate_limited', ['user_id' => $userId]);
        return [
            'success' => false,
            'message' => 'تعداد تلاش‌های شما برای احراز هویت بیش از حد مجاز است. لطفاً یک ساعت دیگر تلاش کنید.'
        ];
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

        $payload = [
            'national_code' => $nationalCode,
            'birth_date' => !empty($data['birth_date']) ? (string)$data['birth_date'] : null,
            'verification_image' => $filename,
        ];

        try {
            return $this->idempotency->executeWithTransaction(
                'kyc_submit',
                $userId,
                $payload,
                function () use ($userId, $filename, $nationalCode, $data, $photoshopCheck, $aiCheck, $uploadResult) {
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
                },
                $ikey
            );
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
}
