<?php

namespace App\Models;
use Core\Model;

use Core\Database;

class SystemSetting extends Model {

    protected static string $table = 'system_settings';

    /**
     * Cache داخل همان Request/Process
     */
    private static array $cache = [];
/**
     * آپدیت مقدار بر اساس id (برای AJAX پنل تنظیمات)
     * نکته: چون cache با key است، اینجا safest اینه که cache کلی پاک بشه.
     */
    public function updateValueById(int $id, string $value): bool
    {
        $sql = "UPDATE system_settings SET value = ?, updated_at = ? WHERE id = ?";

        $stmt = $this->db->query($sql, [
            $value,
            \date('Y-m-d H:i:s'),
            $id
        ]);

        // cache را پاک کن تا تغییرات فوراً دیده شود
        self::$cache = [];

        // اگر query شما PDOStatement برمی‌گرداند:
        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() > 0;
        }

        // fallback
        return (bool)$stmt;
    }

    /**
     * دریافت تنظیم
     */
    public function get(string $key, $default = null)
    {
        if (\array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $sql = "SELECT value, type FROM system_settings WHERE `key` = ? LIMIT 1";
        $row = $this->db->query($sql, [$key])->fetch(\PDO::FETCH_OBJ);

        if (!$row) {
            return $default;
        }

        $value = $this->castValue($row->value ?? null, (string)($row->type ?? 'string'));
        self::$cache[$key] = $value;

        return $value;
    }

    /**
     * تبدیل مقدار به نوع مناسب
     */
    private function castValue($value, string $type)
    {
        $type = \strtolower(\trim($type));

        switch ($type) {
            case 'bool':
            case 'boolean':
                // سازگار با 0/1 و true/false
                return \in_array(\strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);

            case 'int':
            case 'integer':
                return (int)$value;

            case 'float':
            case 'double':
                return (float)$value;

            case 'json':
                $decoded = \json_decode((string)$value, true);
                return (\json_last_error() === JSON_ERROR_NONE) ? $decoded : null;

            default:
                return $value;
        }
    }

    /**
     * تنظیم مقدار با key (فقط اگر وجود داشته باشد)
     */
    public function set(string $key, $value): bool
    {
        $setting = $this->findByKey($key);
        if (!$setting) {
            return false;
        }

        $type = \strtolower(\trim((string)($setting->type ?? 'string')));

        if (\in_array($type, ['bool', 'boolean'], true)) {
            $value = $value ? '1' : '0';
        } elseif ($type === 'json') {
            $value = \json_encode($value, JSON_UNESCAPED_UNICODE);
        } else {
            $value = (string)$value;
        }

        $sql = "UPDATE system_settings SET value = ?, updated_at = NOW() WHERE `key` = ?";
        $stmt = $this->db->query($sql, [$value, $key]);

        unset(self::$cache[$key]);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0; // ممکنه مقدار یکسان بوده باشه
        }

        return (bool)$stmt;
    }

    /**
     * دریافت با کلید
     */
    public function findByKey(string $key): ?object
    {
        $sql = "SELECT * FROM system_settings WHERE `key` = ? LIMIT 1";
        $row = $this->db->query($sql, [$key])->fetch(\PDO::FETCH_OBJ);

        return $row ?: null;
    }

    /**
     * دریافت بر اساس دسته
     */
    public function getByCategory(string $category): array
    {
        $sql = "SELECT * FROM system_settings WHERE category = ? ORDER BY `key` ASC";
        return $this->db->query($sql, [$category])->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت همه
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM system_settings ORDER BY category, `key`";
        return $this->db->query($sql)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت تنظیمات عمومی (برای استفاده در view ها)
     */
    public function getPublic(): array
    {
        $sql = "SELECT `key`, value, type FROM system_settings WHERE is_public = 1";
        $settings = $this->db->query($sql)->fetchAll(\PDO::FETCH_OBJ);

        $result = [];
        foreach ($settings as $setting) {
            $k = (string)($setting->key ?? '');
            if ($k === '') continue;

            $result[$k] = $this->castValue($setting->value ?? null, (string)($setting->type ?? 'string'));
        }

        return $result;
    }

    /**
     * پاک کردن کل Cache
     */
    public function clearCache(): void
    {
        self::$cache = [];
    }
}