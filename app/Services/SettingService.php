<?php

namespace App\Services;

use App\Models\Setting;
use Core\Cache;

use App\Contracts\LoggerInterface;
class SettingService extends \App\Services\BaseService
{
    private \Core\Database $db;
    private Setting $model;
    private Cache $cache;

    // کلید کش در Redis / فایل
    private const CACHE_KEY = 'system:settings';
    private const CACHE_TTL = 60; // دقیقه

    // فایل cache قدیمی (سازگاری به عقب)
    private string $cacheFile;

    public function __construct(
        Setting $model,
        \Core\Database $db,
        Cache $cache
    ) {
        $this->model     = $model;
        $this->db        = $db;
        $this->cache     = $cache;
        $this->cacheFile = __DIR__ . '/../../storage/cache/system_settings.php';
    }

    // ─────────────────────────────────────────────────
    //  بارگذاری تنظیمات
    // ─────────────────────────────────────────────────

    public function load(): array
    {
        // ① Redis / File Cache
        $cached = $this->cache->get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        // ② فایل PHP قدیمی (اگر Redis در دسترس نبود و فایل وجود داشت)
        if ($this->cache->driver() === 'file' && file_exists($this->cacheFile)) {
            $data = include $this->cacheFile;
            if (is_array($data)) {
                return $data;
            }
        }

        // ③ دیتابیس
        $settings = $this->model->all();

        // ذخیره در کش
        $this->cache->put(self::CACHE_KEY, $settings, self::CACHE_TTL);

        // ذخیره فایل PHP (فقط در حالت فایل — برای سازگاری)
        if ($this->cache->driver() === 'file') {
            $this->writePhpCacheFile($settings);
        }

        return $settings;
    }

    // ─────────────────────────────────────────────────
    //  Get / Update
    // ─────────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->load();
        return $all[$key] ?? $default;
    }

    public function updateById(int $id, string $key, string $value): bool
    {
        $row = $this->db->query(
            "SELECT `key` FROM system_settings WHERE id = ? LIMIT 1",
            [$id]
        )->fetch(\PDO::FETCH_ASSOC);

        if (!$row || (string) $row['key'] !== $key) {
            return false;
        }

        $this->db->query(
            "UPDATE system_settings SET `value` = ?, updated_at = NOW() WHERE id = ?",
            [$value, $id]
        );

        $this->clearCache();
        return true;
    }

    public function loadAll(): array
    {
        $rows = $this->db->query(
            "SELECT `key`, `value`, `type` FROM system_settings"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $k = (string) ($r['key'] ?? '');
            if ($k === '') {
                continue;
            }
            $out[$k] = $r['value'];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────
    //  Cache Management
    // ─────────────────────────────────────────────────

    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);

        // فایل PHP قدیمی هم پاک می‌شود
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    // ─────────────────────────────────────────────────
    //  Private
    // ─────────────────────────────────────────────────

    private function writePhpCacheFile(array $settings): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = "<?php\nreturn " . var_export($settings, true) . ";\n";
        file_put_contents($this->cacheFile, $export);
    }
}

