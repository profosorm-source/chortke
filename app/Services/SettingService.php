<?php

declare(strict_types=1);

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

    // فایل cache استاندارد JSON
    private string $cacheFile;

    public function __construct(
        Setting $model,
        \Core\Database $db,
        Cache $cache,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->model     = $model;
        $this->db        = $db;
        $this->cache     = $cache;
        $this->cacheFile = __DIR__ . '/../../storage/cache/system_settings.json';
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

        // ② فایل JSON (اگر Redis در دسترس نبود و فایل وجود داشت)
        if ($this->cache->driver() === 'file' && file_exists($this->cacheFile)) {
            $raw = @file_get_contents($this->cacheFile);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        // ③ دیتابیس
        $settings = $this->model->all();

        // ذخیره در کش
        $this->cache->put(self::CACHE_KEY, $settings, self::CACHE_TTL);

        // ذخیره فایل JSON (فقط در حالت فایل — برای سازگاری)
        if ($this->cache->driver() === 'file') {
            $this->writeJsonCacheFile($settings);
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

        $stmt = $this->db->query(
            "UPDATE system_settings SET `value` = ?, updated_at = NOW() WHERE id = ?",
            [$value, $id]
        );

        if ($stmt->rowCount() === 0) {
            return false;
        }

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

    public function getByCategory(string $category): array
    {
        return $this->model->getByCategory($category);
    }

    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function findByKey(string $key): ?object
    {
        return $this->model->findByKey($key);
    }

    public function set(string $key, string $value): bool
    {
        $ok = $this->model->set($key, $value);
        if ($ok) {
            $this->clearCache();
        }
        return $ok;
    }

    public function setMany(array $settings): bool
    {
        $ok = $this->model->setMany($settings);
        if ($ok) {
            $this->clearCache();
        }
        return $ok;
    }

    public function updateValueById(int $id, string $value): bool
    {
        $ok = $this->model->updateValueById($id, $value);
        if ($ok) {
            $this->clearCache();
        }
        return $ok;
    }

    // ─────────────────────────────────────────────────
    //  Cache Management
    // ─────────────────────────────────────────────────

    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_KEY);

        // فایل کش JSON هم پاک می‌شود
        if (file_exists($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    // ─────────────────────────────────────────────────
    //  Private
    // ─────────────────────────────────────────────────

    private function writeJsonCacheFile(array $settings): void
    {
        try {
            $dir = dirname($this->cacheFile);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
                    $this->logger->error('settings.mkdir_failed', ['dir' => $dir]);
                    return;
                }
            }

            // Secure the directory to prevent direct HTTP access
            $htaccessPath = $dir . '/.htaccess';
            if (!file_exists($htaccessPath)) {
                file_put_contents($htaccessPath, "Order Deny,Allow\nDeny from all\n");
            }

            $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json !== false) {
                file_put_contents($this->cacheFile, $json);
            }
        } catch (\Throwable $e) {
            $this->logger->error('settings.write_cache_failed', ['error' => $e->getMessage()]);
        }
    }
}

