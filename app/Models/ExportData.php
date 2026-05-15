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
     * ماسک کردن داده‌های حساس (PII)
     */
    private function maskSensitiveRow(array $row): array
    {
        if (isset($row['email'])) {
            $parts = explode('@', (string)$row['email']);
            if (count($parts) === 2) {
                $row['email'] = substr($parts[0], 0, 3) . '***@' . $parts[1];
            }
        }
        if (isset($row['phone'])) {
            $row['phone'] = substr((string)$row['phone'], 0, 4) . '***' . substr((string)$row['phone'], -2);
        }
        if (isset($row['mobile'])) {
            $row['mobile'] = substr((string)$row['mobile'], 0, 4) . '***' . substr((string)$row['mobile'], -2);
        }
        return $row;
    }

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

    public function getUsersStatement(?string $dateFrom = null, ?string $dateTo = null, ?string $kycStatus = null, ?string $tierLevel = null, int $limit = self::MAX_EXPORT_LIMIT): \PDOStatement
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
        if ($kycStatus !== null) {
            $where[] = 'kyc_status = ?';
            $params[] = $kycStatus;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT id, full_name, email, mobile, kyc_status, level_slug, status, created_at, last_login
              FROM users {$whereClause}
              ORDER BY created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function getUsers(?string $dateFrom = null, ?string $dateTo = null, ?string $kycStatus = null, ?string $tierLevel = null): array
    {
        $stmt = $this->getUsersStatement($dateFrom, $dateTo, $kycStatus, $tierLevel);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات تراکنش‌ها (Streamable)
     */
    public function getTransactionsStatement(?string $dateFrom = null, ?string $dateTo = null, ?string $type = null, ?string $status = null, int $limit = self::MAX_EXPORT_LIMIT): \PDOStatement
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 't.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $where[] = 't.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        if ($type !== null) {
            $where[] = 't.type = ?';
            $params[] = $type;
        }
        if ($status !== null) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT t.id, t.transaction_id, u.full_name, t.type, t.currency, t.amount, t.balance_before, t.balance_after, t.status, t.created_at
              FROM transactions t
              LEFT JOIN users u ON t.user_id = u.id
              {$whereClause}
              ORDER BY t.created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function getTransactions(?string $dateFrom = null, ?string $dateTo = null, ?string $type = null, ?string $status = null): array
    {
        $stmt = $this->getTransactionsStatement($dateFrom, $dateTo, $type, $status);
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
     * L-08: Note - For large datasets (>5000 users), consider cursor-based pagination
     * to avoid memory overflow. Current implementation loads all data into memory.
     * Consider implementing chunked export: export in batches of 1000 rows
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
     * صادرات برداشت‌ها (Streamable)
     */
    public function getWithdrawalsStatement(?string $dateFrom = null, ?string $dateTo = null, ?string $status = null, ?string $currency = null, int $limit = self::MAX_EXPORT_LIMIT): \PDOStatement
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'w.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $where[] = 'w.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        if ($status !== null) {
            $where[] = 'w.status = ?';
            $params[] = $status;
        }
        if ($currency !== null) {
            $where[] = 'w.currency = ?';
            $params[] = $currency;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT w.id, w.transaction_id, u.full_name, u.email, w.amount, w.fee, w.final_amount, w.currency, w.status, w.method, w.created_at
              FROM withdrawals w
              LEFT JOIN users u ON w.user_id = u.id
              {$whereClause}
              ORDER BY w.created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function getWithdrawals(?string $dateFrom = null, ?string $dateTo = null, ?string $status = null, ?string $currency = null): array
    {
        $stmt = $this->getWithdrawalsStatement($dateFrom, $dateTo, $status, $currency);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }

    /**
     * صادرات Audit Trail (Streamable)
     */
    public function getAuditTrailStatement(?string $dateFrom = null, ?string $dateTo = null, ?string $event = null, ?int $userId = null, int $limit = self::MAX_EXPORT_LIMIT): \PDOStatement
    {
        $limit = \max(1, \min($limit, self::MAX_EXPORT_LIMIT));
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'at.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null) {
            $where[] = 'at.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        if ($event !== null) {
            $where[] = 'at.event = ?';
            $params[] = $event;
        }
        if ($userId !== null) {
            $where[] = 'at.user_id = ?';
            $params[] = $userId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT at.id, at.event, u.full_name as user_name, au.full_name as actor_name, at.context, at.ip_address, at.created_at
              FROM audit_trail at
              LEFT JOIN users u ON at.user_id = u.id
              LEFT JOIN users au ON at.actor_id = au.id
              {$whereClause}
              ORDER BY at.created_at DESC
              LIMIT :limit"
        );

        foreach ($params as $index => $val) {
            $stmt->bindValue($index + 1, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt;
    }

    public function getAuditTrail(?string $dateFrom = null, ?string $dateTo = null, ?string $event = null, ?int $userId = null): array
    {
        $stmt = $this->getAuditTrailStatement($dateFrom, $dateTo, $event, $userId);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return $this->sanitizeRows($rows);
    }
}
