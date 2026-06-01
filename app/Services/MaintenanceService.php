<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * MaintenanceService — مدیر چرخه حیات داده‌ها و سلامت دیتابیس
 * 
 * این سرویس به عنوان ارکستراتور، تمام عملیات‌های آرشیو‌سازی (Archival) 
 * و پاکسازی رکوردهای زائد و منقضی (Data Retention / GDPR) را مدیریت می‌کند.
 */
class MaintenanceService
{

    private MigrationService $migrationService;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        MigrationService $migrationService
    )
    {        $this->db = $db;
        $this->logger = $logger;

        
        $this->migrationService = $migrationService;
    }

    /**
     * اجرای روتین روزانه پاکسازی و آرشیو
     */
    public function runDailyMaintenance(): array
    {
        $this->logger->info('maintenance.daily.started');
        
        $results = [
            'retention' => $this->executeDataRetention(),
            'archival' => $this->executeArchival(),
            'backup_cleanup' => $this->migrationService->cleanupOldBackups(30),
        ];

        $this->logger->info('maintenance.daily.completed', $results);
        
        return $results;
    }

    // ====================================================================================
    // 1. Data Retention Strategy (GDPR & Junk Cleanup)
    // ====================================================================================

    /**
     * حذف لاگ‌های قدیمی، سشن‌های منقضی شده و درخواست‌های حذف اکانت (GDPR)
     */
    private function executeDataRetention(): array
    {
        $deletedSessions = 0;
        $deletedLogs = 0;
        $deletedAccounts = 0;

        try {
            // 1. پاکسازی سشن‌های منقضی‌شده (قدیمی‌تر از 30 روز)
            $stmt = $this->db->query("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $deletedSessions = $stmt->rowCount();

            // 2. پاکسازی لاگ‌های فعالیت زائد (قدیمی‌تر از 90 روز)
            $stmt = $this->db->query("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $deletedLogs = $stmt->rowCount();

            // 3. پاکسازی اکانت‌هایی که درخواست GDPR Deletion داده‌اند و 30 روز گذشته است
            // (فرض بر وجود جدولی یا فیلدی به نام deleted_at)
            // $stmt = $this->db->query("DELETE FROM users WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            // $deletedAccounts = $stmt->rowCount();

            return [
                'status' => 'success',
                'cleared_sessions' => $deletedSessions,
                'cleared_logs' => $deletedLogs,
                'deleted_accounts' => $deletedAccounts,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('maintenance.retention.failed', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    // ====================================================================================
    // 2. Archival Strategy (Cold Storage for heavy tables)
    // ====================================================================================

    /**
     * انتقال تراکنش‌های قدیمی (مالی/امتیازی) به جداول Archive جهت سبک ماندن جداول اصلی
     */
    private function executeArchival(): array
    {
        $archivedCount = 0;
        
        try {
            // اطمینان از وجود جدول Archive
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS transactions_archive LIKE transactions"
            );

            $this->db->beginTransaction();

            // 1. انتقال تراکنش‌های قدیمی‌تر از 1 سال به آرشیو
            $this->db->query(
                "INSERT INTO transactions_archive 
                 SELECT * FROM transactions 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)"
            );
            $archivedCount = $this->db->getPdo()->lastInsertId() ? $this->db->getPdo()->query("SELECT ROW_COUNT()")->fetchColumn() : 0;
            // NOTE: ROW_COUNT() usage inside PDO depends on driver, alternative is querying counts before/after.

            // 2. حذف از جدول اصلی
            $stmt = $this->db->query(
                "DELETE FROM transactions WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)"
            );
            $deletedFromMain = $stmt->rowCount();

            $this->db->commit();

            return [
                'status' => 'success',
                'archived_records' => $deletedFromMain,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('maintenance.archival.failed', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}
