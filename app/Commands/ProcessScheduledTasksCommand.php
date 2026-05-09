<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\User\AccountDeletionService;
use App\Services\DataExportService;
use App\Contracts\LoggerInterface;

/**
 * Command: ProcessScheduledTasksCommand
 * شامل: حذف خودکار حساب‌ها، پاک‌کردن فایل‌های منقضی
 * 
 * استفاده: php app.php process:scheduled-tasks
 */
class ProcessScheduledTasksCommand
{
    private AccountDeletionService $accountDeletionService;
    private DataExportService $dataExportService;
    private LoggerInterface $logger;

    public function __construct(
        AccountDeletionService $accountDeletionService,
        DataExportService $dataExportService,
        LoggerInterface $logger
    ) {
        $this->accountDeletionService = $accountDeletionService;
        $this->dataExportService = $dataExportService;
        $this->logger = $logger;
    }

    /**
     * اجرای کمند با ایزوله‌سازی کامل خطاها
     */
    public function handle(): void
    {
        echo "🔄 در حال پردازش کارهای زمان‌بندی‌شده...\n\n";

        $deletedCount = 0;
        $deletedFiles = 0;

        // ۱. حذف خودکار حساب‌های درخواست‌شده
        try {
            echo "⏳ در حال بررسی درخواست‌های حذف منقضی...\n";
            $deletedCount = $this->accountDeletionService->processExpiredDeletionRequests();
            echo "✅ {$deletedCount} حساب حذف شد\n\n";
        } catch (\Throwable $e) {
            echo "❌ خطا در حذف حساب‌ها: {$e->getMessage()}\n\n";
            $this->logger->error('command.scheduled_tasks.accounts.failed', ['error' => $e->getMessage()]);
        }

        // ۲. حذف فایل‌های منقضی‌شده
        try {
            echo "⏳ در حال پاک‌کردن فایل‌های منقضی...\n";
            $deletedFiles = $this->dataExportService->deleteExpiredExports();
            echo "✅ {$deletedFiles} فایل حذف شد\n\n";
        } catch (\Throwable $e) {
            echo "❌ خطا در حذف فایل‌های منقضی: {$e->getMessage()}\n\n";
            $this->logger->error('command.scheduled_tasks.files.failed', ['error' => $e->getMessage()]);
        }

        echo "🎉 همه کارهای زمان‌بندی‌شده انجام شد\n";
        $this->logger->info('command.scheduled_tasks.completed', [
            'deleted_accounts' => $deletedCount,
            'deleted_files' => $deletedFiles
        ]);
    }
}
