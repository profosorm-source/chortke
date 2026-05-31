<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

/**
 * User Model - Centralized data access for users table
 */
class User extends Model
{
    use Filterable;

    protected static string $table = 'users';
    protected static array $searchable = ['full_name', 'email', 'mobile'];

    protected static array $filterable = [
        'status' => ['status', '='],
        'role' => ['role', '='],
    ];

    /**
     * شخصی‌سازی جستجو برای مدل کاربر (افزودن تطبیق دقیق برای کد معرف)
     */
    public function applySearch(\Core\QueryBuilder $query, ?string $term): \Core\QueryBuilder
    {
        $term = trim((string)$term);
        if (empty($term)) {
            return $query;
        }

        $escaped = $this->escapeLikeValue($term);
        $like = "%{$escaped}%";

        return $query->where(function(\Core\QueryBuilder $q) use ($like, $term) {
            // ۱. جستجوی مشابهت (LIKE) روی فیلدهای استاندارد
            foreach (static::$searchable as $index => $column) {
                if ($index === 0) {
                    $q->where($column, 'LIKE', $like);
                } else {
                    $q->orWhere($column, 'LIKE', $like);
                }
            }
            // ۲. جستجوی دقیق (EXACT) روی فیلدهای خاص دامنه
            $q->orWhere('referral_code', '=', $term);
        });
    }

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

    public function findByCredentialsForUpdate(string $identifier): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM users WHERE (email = ? OR mobile = ?) AND deleted_at IS NULL LIMIT 1 FOR UPDATE",
            [$identifier, $identifier]
        );
    }

    public function findById(int $userId): ?object
    {
        return $this->db->table('users')
            ->where('id', '=', $userId)
            ->whereNull('deleted_at')
            ->first();  // ✓ Returns all columns
    }

    public function findByIdForUpdate(int $userId): ?object
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException("findByIdForUpdate must be called within an active database transaction.");
        }
        
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function incrementFraudScore(int $userId, int $amount = 1): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET fraud_score = COALESCE(fraud_score, 0) + ?, updated_at = NOW() WHERE id = ?",
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

    /**
     * CRITICAL-03 Fix: Atomic check and update for account lockout to prevent race conditions.
     * Checks if the user status is 'active' and updates it to 'locked' if rowCount matches.
     */
    public function lockIfExceededAttempts(int $userId): bool
    {
        $stmt = $this->db->query(
            "UPDATE users SET status = 'locked', updated_at = NOW() 
             WHERE id = ? AND status = 'active'",
            [$userId]
        );
        return (bool)($stmt && $stmt->rowCount() === 1);
    }

    /**
     * CRIT-06 Fix: به‌روزرسانی اتمیک تایم‌اسلایس 2FA برای جلوگیری از Race Condition و Replay Attack
     */
    public function update2FATimeslice(int $userId, int $slice): bool
    {
        $stmt = $this->db->query(
            "UPDATE users SET last_2fa_timeslice = ? 
             WHERE id = ? AND (last_2fa_timeslice IS NULL OR last_2fa_timeslice < ?)",
            [$slice, $userId, $slice]
        );
        return (bool)($stmt && $stmt->rowCount() > 0);
    }

    public function searchWithFilters(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $query = $this->db->table('users')->whereNull('deleted_at');

        if (!empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        $query = $this->applyFilters($query, $filters);

        return $query->orderBy('created_at', 'DESC')
                     ->limit($limit)
                     ->offset($offset)
                     ->get();
    }

    public function countWithFilters(array $filters = []): int
    {
        $query = $this->db->table('users')->whereNull('deleted_at');

        if (!empty($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        $query = $this->applyFilters($query, $filters);

        return $query->count();
    }

    public function getAdminStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count,
                SUM(CASE WHEN status = 'banned' THEN 1 ELSE 0 END) AS banned_count
             FROM users
             WHERE deleted_at IS NULL"
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
     * M39: Fixed status inconsistency (int vs string) - use string values
     */
    public function getUserCountStats(): array
    {
        $row = $this->db->fetch("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'banned' THEN 1 ELSE 0 END) as banned,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
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

    // ==================== RBAC (ROLES & PERMISSIONS) HELPER METHODS ====================

    public function hasPermission(int $userId, string $slug): bool
    {
        $result = $this->db->table('user_roles as ur')
            ->join('role_permissions as rp', 'ur.role_id', '=', 'rp.role_id')
            ->join('permissions as p', 'rp.permission_id', '=', 'p.id')
            ->where('ur.user_id', '=', $userId)
            ->where('p.slug', '=', $slug)
            ->selectRaw('1')
            ->first();
            
        return (bool)$result;
    }

    public function getUserPermissions(int $userId): array
    {
        $result = $this->db->table('user_roles as ur')
            ->join('role_permissions as rp', 'ur.role_id', '=', 'rp.role_id')
            ->join('permissions as p', 'rp.permission_id', '=', 'p.id')
            ->where('ur.user_id', '=', $userId)
            ->select('p.slug')
            ->get();
            
        return array_map(fn($p) => (string)($p->slug ?? ''), $result ?? []);
    }

    public function assignRole(int $userId, int $roleId, ?int $grantedBy = null): bool
    {
        return (bool)$this->db->query(
            "INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, granted_at) VALUES (?, ?, ?, NOW())",
            [$userId, $roleId, $grantedBy]
        );
    }

    public function removeRole(int $userId, int $roleId): bool
    {
        return (bool)$this->db->table('user_roles')
            ->where('user_id', '=', $userId)
            ->where('role_id', '=', $roleId)
            ->delete();
    }
}