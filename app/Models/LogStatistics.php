<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * LogStatistics Model — Statistics Data Access Layer
 * 
 * مسئولیت: آمارگیری از لاگ‌ها و رویدادهای امنیتی
 * استفاده می‌شود در: KpiService, AnalyticsService
 */
class LogStatistics extends Model
{
    /**
     * کل لاگ‌های سیستمی
     */
    public function getTotalSystemLogs(?string $dateFrom = null, ?string $dateTo = null): int
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

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM system_logs {$whereClause}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * کل لاگ‌های امنیتی
     */
    public function getTotalSecurityLogs(?string $dateFrom = null, ?string $dateTo = null): int
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

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM security_logs {$whereClause}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * آمارهای سطح لاگ‌های امنیتی
     */
    public function getSecurityLevelStats(?string $dateFrom = null, ?string $dateTo = null): array
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
     * لاگ‌های خطرناک (CRITICAL, ALERT, EMERGENCY)
     */
    public function getCriticalSecurityLogs(int $days = 7, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM security_logs
             WHERE level IN ('CRITICAL', 'ALERT', 'EMERGENCY')
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$days, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * شمارش حملات امنیتی
     */
    public function countSecurityIncidents(int $days = 7): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM security_logs
             WHERE level IN ('CRITICAL', 'ALERT', 'EMERGENCY')
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * آمارهای IP‌های مریب
     */
    public function getSuspiciousIPs(int $days = 7, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT ip_address, COUNT(*) as count
             FROM security_logs
             WHERE level IN ('CRITICAL', 'ALERT', 'ERROR')
             AND ip_address IS NOT NULL
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY ip_address
             ORDER BY count DESC
             LIMIT ?"
        );
        $stmt->execute([$days, $limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمارهای لاگ‌های سیستمی بر حسب سطح
     */
    public function getSystemLevelStats(?string $dateFrom = null, ?string $dateTo = null): array
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
             FROM system_logs {$whereClause}
             GROUP BY level
             ORDER BY count DESC"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * لاگ‌های روزانه
     */
    public function getDailyLogs(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                DATE(created_at) as date,
                SUM(CASE WHEN type = 'system' THEN 1 ELSE 0 END) as system_logs,
                SUM(CASE WHEN type = 'security' THEN 1 ELSE 0 END) as security_logs,
                SUM(CASE WHEN type = 'activity' THEN 1 ELSE 0 END) as activity_logs,
                COUNT(*) as total
             FROM (
                SELECT created_at, 'system' as type FROM system_logs
                UNION ALL
                SELECT created_at, 'security' as type FROM security_logs
                UNION ALL
                SELECT created_at, 'activity' as type FROM activity_logs
             ) logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC"
        );
        $stmt->execute([$days]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * خلاصه آمارهای لاگ
     */
    public function getLogSummary(): array
    {
        return [
            'total_system_logs' => $this->getTotalSystemLogs(),
            'total_security_logs' => $this->getTotalSecurityLogs(),
            'security_level_stats' => $this->getSecurityLevelStats(),
            'system_level_stats' => $this->getSystemLevelStats(),
            'critical_incidents_7d' => $this->countSecurityIncidents(7),
            'suspicious_ips' => $this->getSuspiciousIPs(7, 10),
            'daily_logs_30d' => $this->getDailyLogs(30),
        ];
    }
}
