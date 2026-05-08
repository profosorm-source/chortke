<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class UserLevel extends Model {
/**
     * یافتن با ID
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM user_levels WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * یافتن با slug
     */
    public function findBySlug(string $slug): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM user_levels WHERE slug = ?");
        $stmt->execute([$slug]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * تمام سطوح (مرتب‌شده)
     */
    public function all(int $limit = 100, int $offset = 0, bool $onlyActive = true): array
{
    // شروع کوئری
    $sql = "SELECT * FROM user_levels";
    
    // اگر onlyActive فعال باشد، فیلتر مربوطه را به کوئری اضافه می‌کنیم
    if ($onlyActive) {
        $sql .= " WHERE is_active = 1";
    }

    // اضافه کردن ترتیب و محدود کردن نتایج
    $sql .= " ORDER BY sort_order ASC LIMIT :limit OFFSET :offset";

    // آماده‌سازی و اجرای کوئری
    $stmt = $this->db->prepare($sql);
    
    // بایند کردن پارامترها
    $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, \PDO::PARAM_INT);

    // اجرای کوئری
    $stmt->execute();

    // بازگشت نتایج
    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}

    /**
     * بروزرسانی سطح
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = [
            'name', 'icon', 'color', 'sort_order',
            'min_active_days', 'min_completed_tasks', 'min_total_earning', 'min_total_earning_usdt',
            'purchase_price_irt', 'purchase_price_usdt', 'purchase_duration_days',
            'earning_bonus_percent', 'referral_bonus_percent', 'daily_task_limit_bonus',
            'withdrawal_limit_bonus', 'priority_support', 'special_badge', 'is_active',
        ];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) return false;
        $values[] = $id;

        $stmt = $this->db->prepare("UPDATE user_levels SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * سطح بالاتر بعدی
     */
    public function getNextLevel(string $currentSlug): ?object
    {
        $current = $this->findBySlug($currentSlug);
        if (!$current) return null;

        $stmt = $this->db->prepare("
            SELECT * FROM user_levels 
            WHERE sort_order > ? AND is_active = 1
            ORDER BY sort_order ASC 
            LIMIT 1
        ");
        $stmt->execute([$current->sort_order]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * سطح پایین‌تر بعدی
     */
    public function getPreviousLevel(string $currentSlug): ?object
    {
        $current = $this->findBySlug($currentSlug);
        if (!$current) return null;

        $stmt = $this->db->prepare("
            SELECT * FROM user_levels 
            WHERE sort_order < ? AND is_active = 1
            ORDER BY sort_order DESC 
            LIMIT 1
        ");
        $stmt->execute([$current->sort_order]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * بالاترین سطح قابل دسترسی با فعالیت
     */
    public function getEligibleLevel(int $activeDays, int $completedTasks, float $totalEarning, float $totalEarningUsdt): ?object
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_levels 
            WHERE is_active = 1
            AND min_active_days <= ?
            AND min_completed_tasks <= ?
            AND min_total_earning <= ?
            AND min_total_earning_usdt <= ?
            ORDER BY sort_order DESC
            LIMIT 1
        ");
        $stmt->execute([$activeDays, $completedTasks, $totalEarning, $totalEarningUsdt]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * آمار کاربران هر سطح
     */
    public function getUserCountPerLevel(): array
    {
        $stmt = $this->db->prepare("
            SELECT level_slug, COUNT(*) as user_count 
            FROM users 
            WHERE deleted_at IS NULL
            GROUP BY level_slug
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->level_slug] = (int) $row->user_count;
        }
        return $result;
    }

    /**
     * ایجاد سطح جدید
     */
    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_levels (
                name, slug, icon, color, sort_order,
                min_active_days, min_completed_tasks, min_total_earning, min_total_earning_usdt,
                purchase_price_irt, purchase_price_usdt, purchase_duration_days,
                earning_bonus_percent, referral_bonus_percent, daily_task_limit_bonus,
                withdrawal_limit_bonus, priority_support, special_badge, is_active,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?,
                NOW(), NOW()
            )
        ");

        $ok = $stmt->execute([
            $data['name'],
            $data['slug'],
            $data['icon'] ?? 'workspace_premium',
            $data['color'] ?? '#c0c0c0',
            $data['sort_order'] ?? 0,
            $data['min_active_days'] ?? 0,
            $data['min_completed_tasks'] ?? 0,
            $data['min_total_earning'] ?? 0,
            $data['min_total_earning_usdt'] ?? 0,
            $data['purchase_price_irt'] ?? 0,
            $data['purchase_price_usdt'] ?? 0,
            $data['purchase_duration_days'] ?? 30,
            $data['earning_bonus_percent'] ?? 0,
            $data['referral_bonus_percent'] ?? 0,
            $data['daily_task_limit_bonus'] ?? 0,
            $data['withdrawal_limit_bonus'] ?? 0,
            $data['priority_support'] ?? 0,
            $data['special_badge'] ?? 0,
            $data['is_active'] ?? 1,
        ]);

        if (!$ok) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    /**
     * حذف سطح
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_levels WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * بررسی وجود slug تکراری
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM user_levels WHERE slug = ?";
        $params = [$slug];
        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * بیشترین sort_order موجود
     */
    public function getMaxSortOrder(): int
    {
        $stmt = $this->db->prepare("SELECT MAX(sort_order) FROM user_levels");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

}