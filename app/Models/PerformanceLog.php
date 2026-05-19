<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * PerformanceLog Model — Data Access Layer
 * 
 * مسئولیت: CRUD operations روی performance_logs table
 * نوشتاری: متریکس عملکرد، زمان پاسخ، مصرف memory
 */
class PerformanceLog extends Model
{
    protected static string $table = 'performance_logs';
    private static ?bool $hasExtendedSchema = null;

    private function checkExtendedSchema(): bool
    {
        if (self::$hasExtendedSchema === null) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM performance_logs LIKE 'user_id'");
                self::$hasExtendedSchema = ($stmt && $stmt->rowCount() > 0);
            } catch (\Throwable $e) {
                self::$hasExtendedSchema = false;
            }
        }
        return self::$hasExtendedSchema;
    }

    /**
     * درج رکورد جدید
     */
    public function insert(array $data): bool
    {
        try {
            if ($this->checkExtendedSchema()) {
                $stmt = $this->db->prepare(
                    "INSERT INTO performance_logs 
                    (request_id, metric, value, context, created_at, user_id, endpoint, method, status_code, db_queries, cache_hits, cache_misses, memory_peak)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                return $stmt->execute([
                    $data['request_id'] ?? null,
                    $data['metric'] ?? 'unknown',
                    $data['value'] ?? 0,
                    $data['context'] ?? null,
                    $data['user_id'] ?? null,
                    $data['endpoint'] ?? null,
                    $data['method'] ?? null,
                    $data['status_code'] ?? null,
                    $data['db_queries'] ?? 0,
                    $data['cache_hits'] ?? 0,
                    $data['cache_misses'] ?? 0,
                    $data['memory_peak'] ?? null,
                ]);
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO performance_logs 
                    (request_id, metric, value, context, created_at)
                    VALUES (?, ?, ?, ?, NOW())"
                );
                return $stmt->execute([
                    $data['request_id'] ?? null,
                    $data['metric'] ?? 'unknown',
                    $data['value'] ?? 0,
                    $data['context'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('model.performance_log.insert.failed', [
                'channel' => 'model',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * دریافت لاگ‌های عملکردی با فیلتر
     */
    public function getPaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $metric = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        // C-04: Enforce hard ceiling on pagination depths to prevent unbounded pagination
        $page = max(1, min(10000, $page));
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($metric !== null) {
            $where[] = 'metric = ?';
            $params[] = $metric;
        }

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM performance_logs {$whereClause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Data
        $dataStmt = $this->db->prepare(
            "SELECT * FROM performance_logs {$whereClause}
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
        $stmt = $this->db->prepare("SELECT * FROM performance_logs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * دریافت لاگ‌های اخیر برای یک متریک
     */
    public function getRecentByMetric(string $metric, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        $stmt = $this->db->prepare(
            "SELECT * FROM performance_logs 
             WHERE metric = ?
             ORDER BY created_at DESC
             LIMIT ?"
        );

        $stmt->execute([$metric, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * دریافت متریکس منحصرِ فرد
     */
    public function getUniqueMetrics(): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT metric FROM performance_logs 
             ORDER BY metric ASC"
        );

        $stmt->execute();
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'metric');
    }

    /**
     * شمارش لاگ‌ها
     */
    public function count(?string $metric = null): int
    {
        $where = [];
        $params = [];

        if ($metric !== null) {
            $where[] = 'metric = ?';
            $params[] = $metric;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM performance_logs {$whereClause}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * محاسبه آمارهای یک متریک
     */
    public function getMetricStats(string $metric, ?string $dateFrom = null, ?string $dateTo = null): ?array
    {
        $where = ['metric = ?'];
        $params = [$metric];

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as count,
                AVG(value) as avg,
                MIN(value) as min,
                MAX(value) as max,
                STDDEV(value) as stddev
             FROM performance_logs {$whereClause}"
        );

        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            return null;
        }

        return [
            'metric' => $metric,
            'count' => (int) $result['count'],
            'avg' => (float) ($result['avg'] ?? 0),
            'min' => (float) ($result['min'] ?? 0),
            'max' => (float) ($result['max'] ?? 0),
            'stddev' => (float) ($result['stddev'] ?? 0),
        ];
    }

    /**
     * دریافت متریکس بدترین عملکرد
     */
    public function getWorstPerformers(int $limit = 10, ?int $days = null): array
    {
        $where = [];
        $params = [];

        if ($days !== null) {
            $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $days;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT metric, value, created_at
             FROM performance_logs {$whereClause}
             ORDER BY value DESC
             LIMIT ?"
        );

        $stmt->execute([...$params, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * حذف لاگ‌های قدیمی تر از تعداد روز
     */
    public function deleteOlderThan(int $days = 90): int
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM performance_logs
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$days]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $this->logger->error('model.performance_log.delete_older_than.failed', [
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
                    "DELETE FROM performance_logs
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
            $this->logger->error('model.performance_log.delete_older_than_chunked.failed', [
                'channel' => 'model',
                'error' => $e->getMessage(),
            ]);
        }

        return $totalDeleted;
    }
}
