<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DataExport;
use App\Models\KYCVerification;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\Wallet;
use Core\Cache;
use App\Contracts\LoggerInterface;

/**
 * DataExportService — صادرکردن داده‌های کاربر
 */
class DataExportService
{
    private DataExport $exportModel;
    private User $userModel;
    private Transaction $transactionModel;
    private Wallet $walletModel;
    private KYCVerification $kycVerificationModel;
    private UserSetting $userSettingModel;


    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        DataExport $exportModel,
        User $userModel,
        Transaction $transactionModel,
        Wallet $walletModel,
        KYCVerification $kycVerificationModel,
        UserSetting $userSettingModel
    ) {        $this->logger = $logger;

        
        $this->exportModel = $exportModel;
        $this->userModel = $userModel;
        $this->transactionModel = $transactionModel;
        $this->walletModel = $walletModel;
        $this->kycVerificationModel = $kycVerificationModel;
        $this->userSettingModel = $userSettingModel;
        }

    /**
     * ایجاد درخواست صادرکردن
     */
    public function requestExport(int $userId, string $format): ?int
    {
        if (!in_array($format, ['json', 'csv'], true)) {
            $this->logger->warning('data_export.invalid_format', ['format' => $format, 'user_id' => $userId]);
            return null;
        }

        try {
            $exportId = $this->exportModel->createExport($userId, $format);
            $this->logger->info('data_export.requested', ['user_id' => $userId, 'format' => $format, 'export_id' => $exportId]);
            return $exportId;
        } catch (\Exception $e) {
            $this->logger->error('data_export.request_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * صادرکردن داده‌های JSON
     */
    public function exportJSON(int $userId): ?string
    {
        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                return null;
            }

            $data = [
                'user' => $this->sanitizeUserData($user),
                'transactions' => $this->getUserTransactions($userId),
                'wallet' => $this->getUserWallet($userId),
                'kyc' => $this->getUserKYC($userId),
                'settings' => $this->getUserSettings($userId),
                'exported_at' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get(),
            ];

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $this->logger->error('data_export.json_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * صادرکردن داده‌های CSV
     */
    public function exportCSV(int $userId): ?string
    {
        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                return null;
            }

            $csv = "نام,مقدار\n";

            // اطلاعات کاربر
            $csv .= "نام کاربری,\"" . $user['username'] . "\"\n";
            $csv .= "نام کامل,\"" . $user['full_name'] . "\"\n";
            $csv .= "ایمیل,\"" . $user['email'] . "\"\n";
            $csv .= "موبایل,\"" . ($user['mobile'] ?? 'ندارد') . "\"\n";
            $csv .= "تاریخ عضویت,\"" . $user['created_at'] . "\"\n";

            // آمار تراکنش‌ها
            $transactions = $this->getUserTransactions($userId);
            $csv .= "\n--- تراکنش‌ها ---\n";
            $csv .= "کل تراکنش‌ها," . count($transactions) . "\n";
            $totalAmount = array_sum(array_map(fn($t) => $t['amount'], $transactions));
            $csv .= "کل مبلغ," . $totalAmount . " تومان\n";

            // آمار کیف‌پول
            $wallet = $this->getUserWallet($userId);
            $csv .= "\n--- کیف‌پول ---\n";
            $csv .= "موجودی,\"" . ($wallet['balance'] ?? 0) . " تومان\"\n";

            return $csv;
        } catch (\Exception $e) {
            $this->logger->error('data_export.csv_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ذخیره فایل صادرشده
     */
    public function saveExportFile(int $exportId, string $format, string $content): ?string
    {
        try {
            $timestamp = date('YmdHis');
            $filename = "export_{$exportId}_{$timestamp}.{$format}";
            $filepath = storage_path("exports/{$filename}");

            // ایجاد دایرکتوری اگر وجود نداشته باشد
            if (!is_dir(dirname($filepath))) {
                mkdir(dirname($filepath), 0755, true);
            }

            file_put_contents($filepath, $content);

            // بروزرسانی وضعیت
            $this->exportModel->updateStatus($exportId, 'completed', $filepath);

            $this->logger->info('data_export.file_saved', ['export_id' => $exportId, 'filepath' => $filepath]);
            return $filepath;
        } catch (\Exception $e) {
            $this->logger->error('data_export.save_failed', ['export_id' => $exportId, 'error' => $e->getMessage()]);
            $this->exportModel->updateStatus($exportId, 'failed', null, $e->getMessage());
            return null;
        }
    }

    /**
     * حذف فایل‌های منقضی
     */
    public function deleteExpiredExports(): int
    {
        try {
            $expiredExports = $this->exportModel->getExpiredExports();
            $deleted = 0;

            $baseExportDir = function_exists('storage_path') ? realpath(storage_path('exports')) : null;

            foreach ($expiredExports as $export) {
                if (!empty($export['file_path'])) {
                    $realPath = realpath($export['file_path']);
                    // تایید قرار داشتن مسیر فایل در پوشه مجاز exports جهت جلوگیری از Path Traversal
                    if ($realPath !== false && $baseExportDir !== false && strpos($realPath, $baseExportDir) === 0) {
                        $lockFile = $realPath . '.lock';
                        if (file_exists($lockFile)) {
                            continue;
                        }
                        
                        touch($lockFile);
                        try {
                            if (file_exists($realPath)) {
                                unlink($realPath);
                            }
                            $this->exportModel->clearFilePath((int)$export['id']);
                            $deleted++;
                        } finally {
                            @unlink($lockFile);
                        }
                    } else {
                        $this->exportModel->clearFilePath((int)$export['id']);
                    }
                } else {
                    $this->exportModel->clearFilePath((int)$export['id']);
                }
            }

            $this->logger->info('data_export.expired_deleted', ['count' => $deleted]);
            return $deleted;
        } catch (\Exception $e) {
            $this->logger->error('data_export.delete_expired_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * دریافت تراکنش‌های کاربر
     */
    private function getUserTransactions(int $userId): array
    {
        return $this->transactionModel->getRecentByUserId($userId, 100);
    }

    /**
     * دریافت کیف‌پول کاربر
     */
    private function getUserWallet(int $userId): array
    {
        $wallet = $this->walletModel->findByUserId($userId);
        if (!$wallet) {
            return [];
        }

        return [
            'balance_irt' => (float)($wallet->balance_irt ?? 0),
            'balance_usdt' => (float)($wallet->balance_usdt ?? 0),
            'locked_irt' => (float)($wallet->locked_irt ?? 0),
            'locked_usdt' => (float)($wallet->locked_usdt ?? 0),
            'total_irt' => (float)($wallet->balance_irt ?? 0) + (float)($wallet->locked_irt ?? 0),
            'total_usdt' => (float)($wallet->balance_usdt ?? 0) + (float)($wallet->locked_usdt ?? 0),
            'currency' => 'multi',
            'balance' => (float)($wallet->balance_irt ?? 0),
        ];
    }

    /**
     * دریافت KYC کاربر
     */
    private function getUserKYC(int $userId): array
    {
        $kyc = $this->kycVerificationModel->findByUserId($userId);
        if (!$kyc) {
            return [];
        }

        return [
            'status' => $kyc->status,
            'verified_at' => $kyc->verified_at ?? null,
            'document_type' => $kyc->document_type ?? null,
        ];
    }

    /**
     * دریافت تنظیمات کاربر
     */
    private function getUserSettings(int $userId): array
    {
        $settings = $this->userSettingModel->getUserSettings($userId);

        $result = [];
        foreach ($settings as $setting) {
            $result[$setting['setting_key']] = $setting['setting_value'];
        }
        return $result;
    }

    /**
     * پاکسازی داده‌های حساس
     */
    private function sanitizeUserData(mixed $user): array
    {
        $userArray = is_object($user) ? (array)$user : $user;
        return [
            'id' => $userArray['id'] ?? null,
            'username' => $userArray['username'] ?? null,
            'full_name' => $userArray['full_name'] ?? null,
            'email' => $userArray['email'] ?? null,
            'mobile' => $userArray['mobile'] ?? null,
            'kyc_status' => $userArray['kyc_status'] ?? null,
            'created_at' => $userArray['created_at'] ?? null,
            'updated_at' => $userArray['updated_at'] ?? null,
        ];
    }
}
