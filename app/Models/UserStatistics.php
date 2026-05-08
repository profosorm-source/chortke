<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * UserStatistics Model — Statistics Data Access Layer
 * 
 * مسئولیت: آمارگیری از کاربران، سطح‌ها، KYC verification
 * استفاده می‌شود در: KpiService, AnalyticsService
 */
class UserStatistics extends Model
{
    /**
     * کل کاربران
     */
    public function getTotalUsers(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * کاربران فعال در یک بازه زمانی
     */
    public function getActiveUsers(?string $dateFrom = null, ?string $dateTo = null): int
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
            "SELECT COUNT(DISTINCT user_id) FROM activity_logs {$whereClause}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * کاربران جدید
     */
    public function getNewUsers(?string $dateFrom = null, ?string $dateTo = null): int
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
            "SELECT COUNT(*) FROM users {$whereClause}"
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * کاربران بر حسب سطح
     */
    public function getUsersByLevel(): array
    {
        $stmt = $this->db->prepare(
            "SELECT ul.id, ul.name, COUNT(u.id) as count
             FROM user_levels ul
             LEFT JOIN users u ON u.level_id = ul.id
             GROUP BY ul.id, ul.name
             ORDER BY ul.id ASC"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمارهای KYC Verification
     */
    public function getKycStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                SUM(CASE WHEN kyc_status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN kyc_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN kyc_status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN kyc_status IS NULL THEN 1 ELSE 0 END) as not_submitted,
                COUNT(*) as total
             FROM users"
        );
        $stmt->execute();

        $result = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'verified' => (int) ($result['verified'] ?? 0),
            'pending' => (int) ($result['pending'] ?? 0),
            'rejected' => (int) ($result['rejected'] ?? 0),
            'not_submitted' => (int) ($result['not_submitted'] ?? 0),
            'total' => (int) ($result['total'] ?? 0),
        ];
    }

    /**
     * مقایسه روزانه کاربران جدید
     */
    public function getDailyNewUsers(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM users
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC"
        );
        $stmt->execute([$days]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * توزیع کاربران بر حسب جنسیت
     */
    public function getUsersByGender(): array
    {
        $stmt = $this->db->prepare(
            "SELECT gender, COUNT(*) as count
             FROM users
             WHERE gender IS NOT NULL
             GROUP BY gender
             ORDER BY count DESC"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * توزیع کاربران بر حسب کشور
     */
    public function getUsersByCountry(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT country, COUNT(*) as count
             FROM users
             WHERE country IS NOT NULL
             GROUP BY country
             ORDER BY count DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * میانگین عمر حساب کاربری (روز)
     */
    public function getAverageAccountAge(): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(DATEDIFF(NOW(), created_at)) as avg_days
             FROM users"
        );
        $stmt->execute();

        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (float) ($result['avg_days'] ?? 0);
    }

    /**
     * کاربران بدون KYC
     */
    public function getUnverifiedUsers(): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM users WHERE kyc_status IS NULL OR kyc_status != 'verified'"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * کاربران بلاک شده
     */
    public function getBlockedUsers(): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM users WHERE is_blocked = 1"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * کاربران فعال در ۲۴ ساعت اخیر
     */
    public function getActiveUsersLast24Hours(): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM activity_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * خلاصه کل آمارهای کاربران
     */
    public function getUserSummary(): array
    {
        return [
            'total_users' => $this->getTotalUsers(),
            'active_24h' => $this->getActiveUsersLast24Hours(),
            'new_users_today' => $this->getNewUsers(date('Y-m-d'), date('Y-m-d')),
            'kyc_stats' => $this->getKycStats(),
            'by_level' => $this->getUsersByLevel(),
            'unverified_count' => $this->getUnverifiedUsers(),
            'blocked_count' => $this->getBlockedUsers(),
            'avg_account_age_days' => $this->getAverageAccountAge(),
        ];
    }
}
