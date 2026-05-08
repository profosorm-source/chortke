<?php

namespace App\Models;

use Core\Model;

class BankCard extends Model
{
    // جدول کارت‌های بانکی
    protected static string $table = 'bank_cards';

    /**
     * دریافت کارت‌های کاربر
     * فقط کارت‌هایی که soft-delete نشده‌اند.
     */
    public function getUserCards(int $userId, bool $onlyVerified = false): array
    {
        $sql = "SELECT *
                FROM " . static::$table . "
                WHERE user_id = :user_id
                  AND deleted_at IS NULL";

        $params = ['user_id' => $userId];

        if ($onlyVerified) {
            $sql .= " AND status = 'verified'";
        }

        // اول کارت پیش‌فرض، بعد جدیدترها
        // اگر ستون شما is_default نیست و is_primary است، به من بگو تا تغییر بدهم
        $sql .= " ORDER BY is_default DESC, created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد کارت‌های کاربر (حذف‌نشده)
     */
    public function countUserCards(int $userId): int
    {
        $sql = "SELECT COUNT(*) as count
                FROM " . static::$table . "
                WHERE user_id = :user_id
                  AND deleted_at IS NULL";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($row->count ?? 0);
    }

    /**
     * دریافت کارت پیش‌فرض کاربر (فقط verified و حذف‌نشده)
     */
    public function getPrimaryCard(int $userId): ?object
    {
        $sql = "SELECT *
                FROM " . static::$table . "
                WHERE user_id = :user_id
                  AND is_default = 1
                  AND status = 'verified'
                  AND deleted_at IS NULL
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /**
     * تنظیم کارت پیش‌فرض
     * توجه: Delete واقعی نداریم. فقط روی کارت‌های حذف‌نشده عملیات می‌کنیم.
     */
    public function setPrimary(int $cardId, int $userId): bool
    {
        // 1) همه کارت‌های کاربر را غیر پیش‌فرض کن
        $stmt = $this->db->prepare("
            UPDATE " . static::$table . "
            SET is_default = 0, updated_at = NOW()
            WHERE user_id = :user_id AND deleted_at IS NULL
        ");
        $stmt->execute(['user_id' => $userId]);

        // 2) کارت انتخابی را پیش‌فرض کن
        $stmt = $this->db->prepare("
            UPDATE " . static::$table . "
            SET is_default = 1, updated_at = NOW()
            WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
        ");

        return $stmt->execute([
            'id' => $cardId,
            'user_id' => $userId
        ]);
    }
}