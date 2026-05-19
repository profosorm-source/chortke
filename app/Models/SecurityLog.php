<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * SecurityLog Model — Data Access Layer
 * 
 * مسئولیت: CRUD operations روی security_logs table
 * نوشتاری: رویدادهای امنیتی، حملات، ورودها
 */
class SecurityLog extends Model
{
    protected static string $table = 'security_logs';

    /**
     * درج رکورد جدید
     */
    public function insert(array $data): bool
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO security_logs 
                (request_id, level, type, message, context, user_id, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );

            return $stmt->execute([
                $data['request_id'] ?? null,
                $data['level'] ?? 'WARNING',
                $data['type'] ?? 'security',
                $data['message'] ?? '',
                $data['context'] ?? null,
                $data['user_id'] ?? null,
                $data['ip_address'] ?? null,
                $data['user_agent'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('model.security_log.insert.failed', [
                'channel' => 'model',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * دریافت لاگ‌های امنیتی با فیلتر
     */
    public function getPaginated(
        array $filters = [],
        int $page = 1,
        int $perPage = 20
    ): array {
        // C-04: Enforce hard ceiling on pagination depths to prevent unbounded pagination
        $page = max(1, min(10000, $page));
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if (!empty($filters['level'])) {
            $where[] = 'level = ?';
            $params[] = $filters['level'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['ip_address'])) {
            $where[] = 'ip_address = ?';
            $params[] = $filters['ip_address'];
        }

        if (!empty($filters['search'])) {
            $like = "%{$filters['search']}%";
            $where[] = '(message LIKE ? OR context LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM security_logs {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Data
        $dataStmt = $this->db->prepare(
            "SELECT * FROM security_logs {$whereClause}
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?"
        );
        $dataStmt->execute([...$params, $perPage, $offset]);

        $rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * دریافت یک رکورد با ID
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM security_logs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * دریافت لاگ‌های اخیر
     */
    public function getRecent(int $limit = 50, ?string $level = null): array
    {
        $limit = max(1, min(500, $limit));

        $where = [];
        $params = [];

        if ($level !== null) {
            $where[] = 'level = ?';
            $params[] = $level;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT * FROM security_logs {$whereClause}
             ORDER BY created_at DESC
             LIMIT ?"
        );

        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * دریافت لاگ‌های خطرناک (CRITICAL, ALERT, EMERGENCY)
     */
    public function getCriticalLogs(?int $days = null): array
    {
        $where = ['level IN (?, ?, ?)'];
        $params = ['CRITICAL', 'ALERT', 'EMERGENCY'];

        if ($days !== null) {
            $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare(
            "SELECT * FROM security_logs {$whereClause}
             ORDER BY created_at DESC"
        );

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * شمارش لاگ‌های امنیتی
     */
    public function count(?string $level = null): int
    {
        $where = [];
        $params = [];

        if ($level !== null) {
            $where[] = 'level = ?';
            $params[] = $level;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM security_logs {$whereClause}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * شمارش لاگ‌های خطرناک
     */
    public function countCriticalByUser(int $userId, ?int $days = null): int
    {
        $where = ['user_id = ?', 'level IN (?, ?, ?)'];
        $params = [$userId, 'CRITICAL', 'ALERT', 'EMERGENCY'];

        if ($days !== null) {
            $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM security_logs {$whereClause}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * حذف لاگ‌های قدیمی تر از تعداد روز
     */
    public function deleteOlderThan(int $days = 90): int
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM security_logs
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $this->logger->error('model.security_log.delete_older_than.failed', [
                'channel' => 'model',
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * حذف لاگ‌های قدیمی به‌صورت chunked (برای جداول بزرگ)
     */
    public function deleteOlderThanChunked(int $days = 90, int $chunkSize = 5000): int
    {
        $totalDeleted = 0;
        $maxIterations = 100;

        try {
            for ($i = 0; $i < $maxIterations; $i++) {
                $stmt = $this->db->prepare(
                    "DELETE FROM security_logs
                     WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                     LIMIT ?"
                );
                $stmt->execute([$days, $chunkSize]);
                $batchDeleted = $stmt->rowCount();

                $totalDeleted += $batchDeleted;

                if ($batchDeleted < $chunkSize) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('model.security_log.delete_older_than_chunked.failed', [
                'channel' => 'model',
                'error' => $e->getMessage(),
            ]);
        }

        return $totalDeleted;
    }

    /**
     * دریافت آمارهای سطح
     */
    public function getLevelStats(?string $dateFrom = null, ?string $dateTo = null): array
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
            "SELECT level, COUNT(*) as count
             FROM security_logs {$whereClause}
             GROUP BY level
             ORDER BY count DESC"
        );

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * دریافت آمارهای IP (حملات از کدام IP‌ها)
     */
    public function getIpStats(?int $days = null, int $limit = 20): array
    {
        $where = [];
        $params = [];

        if ($days !== null) {
            $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT ip_address, COUNT(*) as count
             FROM security_logs {$whereClause}
             GROUP BY ip_address
             ORDER BY count DESC
             LIMIT ?"
        );

        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
