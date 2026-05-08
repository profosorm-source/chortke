<?php

namespace App\Commands;

use App\Services\User\AccountDeletionService;
use App\Services\DataExportService;
use Core\Logger;

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
    private Logger $logger;

    public function __construct(
        AccountDeletionService $accountDeletionService,
        DataExportService $dataExportService,
        Logger $logger
    ) {
        $this->accountDeletionService = $accountDeletionService;
        $this->dataExportService = $dataExportService;
        $this->logger = $logger;
    }

    /**
     * اجرای کمند
     */
    public function handle(): void
    {
        echo "🔄 در حال پردازش کارهای زمان‌بندی‌شده...\n\n";

        try {
            // ۱. حذف خودکار حساب‌های درخواست‌شده
            echo "⏳ در حال بررسی درخواست‌های حذف منقضی...\n";
            $deletedCount = $this->accountDeletionService->processExpiredDeletionRequests();
            echo "✅ {$deletedCount} حساب حذف شد\n\n";

            // ۲. حذف فایل‌های منقضی‌شده
            echo "⏳ در حال پاک‌کردن فایل‌های منقضی...\n";
            $deletedFiles = $this->dataExportService->deleteExpiredExports();
            echo "✅ {$deletedFiles} فایل حذف شد\n\n";

            echo "🎉 همه کارهای زمان‌بندی‌شده انجام شد\n";
            $this->logger->info('command.scheduled_tasks.completed', [
                'deleted_accounts' => $deletedCount,
                'deleted_files' => $deletedFiles
            ]);

        } catch (\Exception $e) {
            echo "❌ خطا: {$e->getMessage()}\n";
            $this->logger->error('command.scheduled_tasks.failed', ['error' => $e->getMessage()]);
        }
    }
}
