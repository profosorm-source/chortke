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
    private const MAX_EXPORT_LIMIT = 5000;

    /**
     * پاکسازی داده‌ها از تزریق فرمول در اکسل/CSV (CSV Injection)
     */
    private function sanitizeRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (\is_string($value)) {
                $val = \trim($value);
                if ($val !== '' && \in_array($val[0], ['=', '+', '-', '@'], true)) {
                    $row[$key] = "'" . $value;
                }
            }
        }
        return $row;
    }

    private function sanitizeRows(array $rows): array
    {
        return \array_map([$this, 'sanitizeRow'], $rows);
    }

    /**
     * صادرات کاربران
     */
    public function exportUsers(?string $dateFrom = null, ?string $dateTo = null, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
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
              ORDER BY created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات تراکنش‌ها
     */
    public function exportTransactions(?string $dateFrom = null, ?string $dateTo = null, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
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
              ORDER BY created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات تسک‌ها
     */
    public function exportTasks(?string $status = null, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
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
              ORDER BY created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات submissions
     */
    public function exportSubmissions(?string $status = null, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
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
              ORDER BY submitted_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات آمارهای سرانه
     */
    public function exportUserAnalytics(?string $dateFrom = null, ?string $dateTo = null, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
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
              ORDER BY activity_count DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات شکایات و اختلافات
     */
    public function exportDisputes(?string $status = null, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
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
              ORDER BY created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات لاگ‌های امنیتی
     */
    public function exportSecurityLogs(int $days = 30, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
        $stmt = $this->db->prepare(
            "SELECT id, level, message, user_id, ip_address, user_agent, created_at
             FROM security_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $days, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات wallet transactions
     */
    public function exportWalletTransactions(?int $userId = null, int $limit = self::MAX_EXPORT_LIMIT): array
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
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
              ORDER BY created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات کل داده‌های سیستم (خلاصه)
     */
    public function exportSystemSummary(): array
    {
        $queries = [
            'total_users' => "SELECT COUNT(*) FROM users",
            'total_transactions' => "SELECT COUNT(*) FROM transactions",
            'total_tasks' => "SELECT COUNT(*) FROM custom_tasks",
            'total_submissions' => "SELECT COUNT(*) FROM custom_task_submissions",
            'total_disputes' => "SELECT COUNT(*) FROM disputes",
            'total_security_logs' => "SELECT COUNT(*) FROM security_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        ];

        $results = [
            'export_date' => date('Y-m-d H:i:s')
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $this->db->query($sql);
            $results[$key] = $stmt ? (int)$stmt->fetchColumn() : 0;
        }

        return $results;
    }
}
