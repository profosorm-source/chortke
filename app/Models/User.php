<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * User Model - Centralized data access for users table
 */
class User extends Model
{
    protected static string $table = 'users';

    public function findByEmail(string $email): ?object
    {
        return $this->db->fetch("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
    }

    public function findByMobile(string $mobile): ?object
    {
        return $this->db->fetch("SELECT * FROM users WHERE mobile = ? LIMIT 1", [$mobile]);
    }

    public function findByReferralCode(string $code): ?object
    {
        return $this->db->fetch("SELECT * FROM users WHERE referral_code = ? LIMIT 1", [$code]);
    }

    public function findByCredentials(string $identifier): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM users WHERE (email = ? OR mobile = ?) AND deleted_at IS NULL LIMIT 1",
            [$identifier, $identifier]
        );
    }

    public function findById(int $userId): ?object
    {
        return $this->db->fetch(
            "SELECT id, username, email, status, kyc_status, fraud_score FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1",
            [$userId]
        );
    }

    public function incrementFraudScore(int $userId, int $amount = 1): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET fraud_score = COALESCE(fraud_score, 0) + ? WHERE id = ?",
            [$amount, $userId]
        );
    }

    public function isBlacklisted(int $userId): bool
    {
        $row = $this->db->fetch("SELECT is_blacklisted FROM users WHERE id = ?", [$userId]);
        return (bool)($row->is_blacklisted ?? false);
    }

    public function updateLastLogin(int $userId, string $ip, string $userAgent): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET last_login = NOW(), last_ip = ?, last_user_agent = ?, updated_at = NOW() WHERE id = ?",
            [$ip, $userAgent, $userId]
        );
    }

    public function verifyEmail(int $userId): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL, updated_at = NOW() WHERE id = ?",
            [$userId]
        );
    }

    public function searchWithFilters(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        // ✅ امنیت: استفاده از QueryBuilder به جای string concatenation
        $query = $this->db->table('users')->whereNull('deleted_at');

        if (!empty($filters['search'])) {
            $search = "%{$filters['search']}%";
            $query->where('full_name', 'LIKE', $search)
                  ->orWhere('email', 'LIKE', $search)
                  ->orWhere('mobile', 'LIKE', $search);
        }

        if (!empty($filters['role'])) {
            $query->where('role', '=', $filters['role']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', '=', $filters['status']);
        }

        return $query->orderBy('created_at', 'DESC')
                     ->limit($limit)
                     ->offset($offset)
                     ->get();
    }

    public function countWithFilters(array $filters = []): int
    {
        // ✅ امنیت: استفاده از QueryBuilder
        $query = $this->db->table('users')->whereNull('deleted_at');

        if (!empty($filters['search'])) {
            $search = "%{$filters['search']}%";
            $query->where('full_name', 'LIKE', $search)
                  ->orWhere('email', 'LIKE', $search)
                  ->orWhere('mobile', 'LIKE', $search);
        }

        return $query->count();
    }

    public function getAdminStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN deleted_at IS NULL AND status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN deleted_at IS NULL AND status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count,
                SUM(CASE WHEN status = 'banned' THEN 1 ELSE 0 END) AS banned_count
             FROM users"
        ) ?: (object)[];
    }

    public function getUserSettings(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM user_settings WHERE user_id = ?",
            [$userId]
        );
    }

    public function upsertSetting(int $userId, string $key, string $value): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO user_settings (user_id, setting_key, setting_value, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
            [$userId, $key, $value]
        );
    }

    public function deleteSettings(int $userId): bool
    {
        return (bool)$this->db->query("DELETE FROM user_settings WHERE user_id = ?", [$userId]);
    }

    // ==================== ANALYTICS METHODS ====================

    /**
     * آمار کلی کاربران
     */
    public function getUserCountStats(): array
    {
        $row = $this->db->fetch("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as banned,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as suspended
            FROM users
            WHERE deleted_at IS NULL
        ");
        return [
            'total' => (int)($row->total ?? 0),
            'active' => (int)($row->active ?? 0),
            'banned' => (int)($row->banned ?? 0),
            'suspended' => (int)($row->suspended ?? 0),
        ];
    }

    /**
     * آمار ثبت‌نام جدید
     */
    public function getNewUserStats(): array
    {
        $today = date('Y-m-d');
        $row = $this->db->fetch("
            SELECT
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as new_today,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as new_this_week,
                SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as new_this_month
            FROM users
            WHERE deleted_at IS NULL
        ", [$today]);
        return [
            'new_today' => (int)($row->new_today ?? 0),
            'new_this_week' => (int)($row->new_this_week ?? 0),
            'new_this_month' => (int)($row->new_this_month ?? 0),
        ];
    }

    /**
     * آمار فعالیت کاربران (DAU, WAU, MAU)
     */
    public function getUserActivityStats(): array
    {
        $today = date('Y-m-d');
        $row = $this->db->fetch("
            SELECT
                SUM(CASE WHEN DATE(last_login) = ? THEN 1 ELSE 0 END) as dau,
                SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as wau,
                SUM(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as mau
            FROM users
            WHERE deleted_at IS NULL
        ", [$today]);
        return [
            'dau' => (int)($row->dau ?? 0),
            'wau' => (int)($row->wau ?? 0),
            'mau' => (int)($row->mau ?? 0),
        ];
    }

    /**
     * آمار سطح‌بندی کاربران
     */
    public function getUserTierStats(): array
    {
        $rows = $this->db->fetchAll("
            SELECT COALESCE(tier_level, 'silver') as tier, COUNT(*) as count
            FROM users
            WHERE deleted_at IS NULL
            GROUP BY tier_level
        ");
        $tiers = ['silver' => 0, 'gold' => 0, 'vip' => 0];
        foreach ($rows as $row) {
            $tier = is_array($row) ? $row['tier'] : $row->tier;
            $count = is_array($row) ? $row['count'] : $row->count;
            $tiers[$tier] = (int)$count;
        }
        return $tiers;
    }
}