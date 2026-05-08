<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * ExportData Model — Data Export & Bulk Operations
 * 
 * مسئولیت: عملیات صادرات data و bulk operations
 * استفاده می‌شود در: ExportService, AnalyticsService
 */
class ExportData extends Model
{
    /**
     * صادرات کاربران
     */
    public function exportUsers(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT id, full_name, email, phone, kyc_status, level_id, is_blocked, created_at, updated_at
             FROM users {$whereClause}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات تراکنش‌ها
     */
    public function exportTransactions(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT id, user_id, type, amount, gateway, status, transaction_id, created_at
             FROM transactions {$whereClause}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات تسک‌ها
     */
    public function exportTasks(?string $status = null): array
    {
        $where = [];
        $params = [];

        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT id, creator_id, title, description, category, budget, status, created_at, updated_at
             FROM custom_tasks {$whereClause}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات submissions
     */
    public function exportSubmissions(?string $status = null): array
    {
        $where = [];
        $params = [];

        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT id, task_id, worker_id, status, submitted_at, approved_at, rating, created_at
             FROM custom_task_submissions {$whereClause}
             ORDER BY submitted_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات آمارهای سرانه
     */
    public function exportUserAnalytics(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'al.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'al.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT 
                u.id, u.full_name, u.email,
                COUNT(al.id) as activity_count,
                COUNT(DISTINCT DATE(al.created_at)) as active_days
             FROM users u
             LEFT JOIN activity_logs al ON u.id = al.user_id {$whereClause}
             GROUP BY u.id, u.full_name, u.email
             ORDER BY activity_count DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات شکایات و اختلافات
     */
    public function exportDisputes(?string $status = null): array
    {
        $where = [];
        $params = [];

        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT id, ref_type, ref_id, reporter_id, respondent_id, reason, status, created_at, resolved_at
             FROM disputes {$whereClause}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات لاگ‌های امنیتی
     */
    public function exportSecurityLogs(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, level, message, user_id, ip_address, user_agent, created_at
             FROM security_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC"
        );
        $stmt->execute([$days]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات wallet transactions
     */
    public function exportWalletTransactions(?int $userId = null): array
    {
        $where = [];
        $params = [];

        if ($userId !== null) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT id, user_id, type, amount, balance_before, balance_after, description, created_at
             FROM ledger_entries {$whereClause}
             ORDER BY created_at DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * صادرات کل داده‌های سیستم (خلاصه)
     */
    public function exportSystemSummary(): array
    {
        return [
            'export_date' => date('Y-m-d H:i:s'),
            'total_users' => $this->db->prepare("SELECT COUNT(*) FROM users")->execute() ? $this->db->prepare("SELECT COUNT(*) FROM users")->fetchColumn() : 0,
            'total_transactions' => $this->db->prepare("SELECT COUNT(*) FROM transactions")->execute() ? $this->db->prepare("SELECT COUNT(*) FROM transactions")->fetchColumn() : 0,
            'total_tasks' => $this->db->prepare("SELECT COUNT(*) FROM custom_tasks")->execute() ? $this->db->prepare("SELECT COUNT(*) FROM custom_tasks")->fetchColumn() : 0,
            'total_submissions' => $this->db->prepare("SELECT COUNT(*) FROM custom_task_submissions")->execute() ? $this->db->prepare("SELECT COUNT(*) FROM custom_task_submissions")->fetchColumn() : 0,
            'total_disputes' => $this->db->prepare("SELECT COUNT(*) FROM disputes")->execute() ? $this->db->prepare("SELECT COUNT(*) FROM disputes")->fetchColumn() : 0,
            'total_security_logs' => $this->db->prepare("SELECT COUNT(*) FROM security_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->execute() ? $this->db->prepare("SELECT COUNT(*) FROM security_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() : 0,
        ];
    }
}
