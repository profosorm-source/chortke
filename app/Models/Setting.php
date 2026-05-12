<?php

namespace App\Models;

use Core\Model;
use Core\Cache;

/**
 * Setting - تنظیمات سایت با Cache لایه‌ای
 *
 * دو سطح cache:
 *   1. static در-حافظه (همان request) → فوری
 *   2. فایل-based Cache (بین request‌ها، 10 دقیقه) → کاهش DB hit
 */
class Setting extends Model
{
    protected static string $table = 'system_settings';

    public function __construct(\Core\Database $db)
    {
        parent::__construct($db);
    }

    /** cache در-حافظه برای همان request */
    private static array $memCache = [];

    private const CACHE_KEY    = 'settings_all';
    private const CACHE_TTL    = 10; // دقیقه

    /**
     * دریافت یک تنظیم
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    /**
     * دریافت همه تنظیمات (با دو لایه cache)
     */
    public function all($filters = [], $limit = 100, $offset = 0): array
{
    // لایه 1: در-حافظه
    if (!empty(self::$memCache)) {
        return self::$memCache;
    }

    // لایه 2: فایل cache
    $cached = Cache::getInstance()->get(self::CACHE_KEY);
    if ($cached !== null) {
        self::$memCache = $cached;
        return $cached;
    }

    // لایه 3: دیتابیس
    $rows = $this->db->fetchAll(
        "SELECT `key`, `value` FROM " . static::$table
    );

    $out = [];
    foreach ($rows as $r) {
        $r = (array) $r;
        $out[$r['key']] = $r['value'];
    }

    Cache::getInstance()->put(self::CACHE_KEY, $out, self::CACHE_TTL);
    self::$memCache = $out;

    return $out;
}

    /**
     * ذخیره تنظیم و پاک‌سازی cache
     */
    public function set(string $key, string $value): bool
    {
        $exists = $this->db->fetchColumn(
            "SELECT id FROM " . static::$table . " WHERE `key` = ? LIMIT 1",
            [$key]
        );

        if ($exists) {
            $ok = $this->db->query(
                "UPDATE " . static::$table . " SET `value` = ?, updated_at = NOW() WHERE `key` = ?",
                [$value, $key]
            ) !== false;
        } else {
            $ok = $this->db->query(
                "INSERT INTO " . static::$table . " (`key`, `value`, created_at, updated_at) VALUES (?, ?, NOW(), NOW())",
                [$key, $value]
            ) !== false;
        }

        if ($ok) {
            $this->clearCache();
        }

        return $ok;
    }

    /**
     * ذخیره دسته‌ای
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
     * پاک‌سازی cache (instance)
     */
    public function clearCache(): void
    {
        self::$memCache = [];
        Cache::getInstance()->forget(self::CACHE_KEY);
    }

    /**
     * پاک‌سازی cache (static - برای CacheAdminController)
     */
    public static function clearCacheStatic(): void
    {
        self::$memCache = [];
        Cache::getInstance()->forget(self::CACHE_KEY);
    }
    /**
     * دریافت با کلید
     */
    public function findByKey(string $key): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE `key` = ? LIMIT 1";
        $row = $this->db->query($sql, [$key])->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    /**
     * دریافت بر اساس دسته
     */
    public function getByCategory(string $category): array
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE category = ? ORDER BY `key` ASC";
        return $this->db->query($sql, [$category])->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت همه کامل (به همراه جزئیات رکوردها)
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM " . static::$table . " ORDER BY category, `key`";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * آپدیت مقدار بر اساس id
     */
    public function updateValueById(int $id, string $value): bool
    {
        $sql = "UPDATE " . static::$table . " SET value = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $this->db->query($sql, [$value, $id]);
        
        $this->clearCache();
        
        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }
        return (bool)$stmt;
    }
}
