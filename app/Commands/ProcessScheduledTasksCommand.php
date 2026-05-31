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
    private \App\Domain\Financial\Services\FinancialEscrowService $escrowService;
    private \Core\Database $db;
    private LoggerInterface $logger;

    public function __construct(
        AccountDeletionService $accountDeletionService,
        DataExportService $dataExportService,
        \App\Domain\Financial\Services\FinancialEscrowService $escrowService,
        \Core\Database $db,
        LoggerInterface $logger
    ) {
        $this->accountDeletionService = $accountDeletionService;
        $this->dataExportService = $dataExportService;
        $this->escrowService = $escrowService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * اجرای کمند با پشتیبانی از CliDispatcher
     */
    public function run(array $argv = []): void
    {
        $this->handle();
    }

    /**
     * اجرای کمند با ایزوله‌سازی کامل خطاها
     */
    public function handle(): void
    {
        $this->logger->info('command.scheduled_tasks.starting');

        $deletedCount = 0;
        $deletedFiles = 0;
        $deletedMessages = 0;
        $releasedEscrows = 0;

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

        // ۳. سیاست انقضای پیام‌ها (Message Retention Policy - حذف پیام‌های چت قدیمی‌تر از ۱ سال)
        try {
            $retentionDays = 365;
            
            // حذف پیوست‌های مرتبط با پیام‌های تاریخ‌گذشته ابتدا جهت ممانعت از یتیم شدن کلید خارجی (در صورت وجود ساختار ارتباطی)
            // حذف فیزیکی پیام‌ها
            $stmt = $this->db->query(
                "DELETE FROM direct_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$retentionDays]
            );
            $deletedMessages = $stmt ? $stmt->rowCount() : 0;
            $this->logger->info('command.scheduled_tasks.messages_purged', ['count' => $deletedMessages]);
        } catch (\Throwable $e) {
            $this->logger->error('command.scheduled_tasks.messages.failed', ['error' => $e->getMessage()]);
        }

        // ۴. انقضا و برگشت وجه سپرهای موقت تاریخ‌گذشته (Escrow Cleanup)
        try {
            $releasedEscrows = $this->escrowService->releaseExpiredHolds();
            $this->logger->info('command.scheduled_tasks.escrows_released', ['count' => $releasedEscrows]);
        } catch (\Throwable $e) {
            $this->logger->error('command.scheduled_tasks.escrows.failed', ['error' => $e->getMessage()]);
        }

        $this->logger->info('command.scheduled_tasks.completed', [
            'deleted_accounts' => $deletedCount,
            'deleted_files' => $deletedFiles,
            'deleted_messages' => $deletedMessages,
            'released_escrows' => $releasedEscrows
        ]);
    }
}
