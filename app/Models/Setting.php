<?php

namespace App\Models;

use Core\Model;

/**
 * Setting - مدل خالص دیتابیس برای تنظیمات سیستم
 * 
 * وظایف کش‌گذاری و منطق تجاری اکنون به SettingService منتقل شده است.
 * این کلاس صرفاً مسئول برقراری ارتباط با جدول system_settings می‌باشد.
 */
class Setting extends Model
{
    protected static string $table = 'system_settings';

    public function __construct(\Core\Database $db)
    {
        parent::__construct($db);
    }

    /**
     * دریافت یک تنظیم از دیتابیس (خام)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->findByKey($key);
        return $row ? $row->value : $default;
    }

    /**
     * دریافت لیست کلیدی تنظیمات از دیتابیس (خام)
     */
    public function all($filters = [], $limit = 100, $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `key`, `value` FROM " . static::$table
        );

        $out = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $out[$r['key']] = $r['value'];
        }

        return $out;
    }

    /**
     * ذخیره مقدار یک تنظیم
     */
    public function set(string $key, string $value): bool
    {
        $exists = $this->db->fetchColumn(
            "SELECT id FROM " . static::$table . " WHERE `key` = ? LIMIT 1",
            [$key]
        );

        if ($exists) {
            return $this->db->query(
                "UPDATE " . static::$table . " SET `value` = ?, updated_at = NOW() WHERE `key` = ?",
                [$value, $key]
            ) !== false;
        }

        return $this->db->query(
            "INSERT INTO " . static::$table . " (`key`, `value`, created_at, updated_at) VALUES (?, ?, NOW(), NOW())",
            [$key, $value]
        ) !== false;
    }

    /**
     * ذخیره دسته‌ای تنظیمات
     */
    public function setMany(array $settings): bool
    {
        $ok = true;
        foreach ($settings as $key => $value) {
            if (!$this->set($key, $value)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * دریافت رکورد کامل با کلید
     */
    public function findByKey(string $key): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE `key` = ? LIMIT 1";
        $row = $this->db->query($sql, [$key])->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /**
     * دریافت لیست کامل بر اساس دسته‌بندی
     */
    public function getByCategory(string $category): array
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE `group` = ? ORDER BY `key` ASC";
        return $this->db->query($sql, [$category])->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت تمام رکوردهای کامل (شامل Type) برای لودر سرویس
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM " . static::$table . " ORDER BY `group`, `key`";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آپدیت مقدار بر اساس id
     */
    public function updateValueById(int $id, string $value): bool
    {
        $sql = "UPDATE " . static::$table . " SET value = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->query($sql, [$value, $id]);
        
        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }
        return (bool)$stmt;
    }
}
