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
        $this->logger->info('command.scheduled_tasks.starting');

        $deletedCount = 0;
        $deletedFiles = 0;

        // ۱. حذف خودکار حساب‌های درخواست‌شده
        try {
            $deletedCount = $this->accountDeletionService->processExpiredDeletionRequests();
            $this->logger->info('command.scheduled_tasks.accounts_deleted', ['count' => $deletedCount]);
        } catch (\Throwable $e) {
            $this->logger->error('command.scheduled_tasks.accounts.failed', ['error' => $e->getMessage()]);
        }

        // ۲. حذف فایل‌های منقضی‌شده
        try {
            $deletedFiles = $this->dataExportService->deleteExpiredExports();
            $this->logger->info('command.scheduled_tasks.files_deleted', ['count' => $deletedFiles]);
        } catch (\Throwable $e) {
            $this->logger->error('command.scheduled_tasks.files.failed', ['error' => $e->getMessage()]);
        }
        $this->logger->info('command.scheduled_tasks.completed', [
            'deleted_accounts' => $deletedCount,
            'deleted_files' => $deletedFiles
        ]);
    }
}
