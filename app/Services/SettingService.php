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

    // 🛡️ H17 Fix (CRITICAL): Replace static runtimeCache with instance property
    // to prevent cross-request contamination in long-running processes (Swoole/Octane)
    private ?array $runtimeCache = null;

    // کلید کش مرکزی سیستم
    private const CACHE_KEY = 'system:settings:v2';
    private const CACHE_TTL = 60; // دقیقه

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
        // ✅ Instance cache is automatically reset per request lifecycle
    }

    /**
     * بارگذاری هوشمند و کش‌شده تمام تنظیمات با قابلیت Type-Casting
     */
    public function load(): array
    {
        // ۱. لایه طلایی: کش مستقیم در حافظه (Memory Stack)
        if ($this->runtimeCache !== null) {
            return $this->runtimeCache;
        }

        // ۲. لایه نقره‌ای: کش توزیع شده (Redis / File Driver)
        $cachedData = $this->cache->get(self::CACHE_KEY);
        if (is_array($cachedData)) {
            $this->runtimeCache = $cachedData;
            return $cachedData;
        }

        // ۳. لایه دیتابیس: واکشی و تبدیل هوشمند مقادیر
        try {
            // دریافت کامل سطرها شامل ستون Type
            $rawSettings = $this->model->getAll();
            $parsedSettings = [];

            foreach ($rawSettings as $row) {
                $key = (string)($row->key ?? '');
                if ($key === '') continue;

                // تبدیل هوشمند نوع داده (Smart Casting)
                $parsedSettings[$key] = $this->castValue($row->value ?? '', (string)($row->type ?? 'string'));
            }

            // ذخیره در لایه‌های کش برای مراجعات بعدی
            $this->cache->put(self::CACHE_KEY, $parsedSettings, self::CACHE_TTL);
            $this->runtimeCache = $parsedSettings;

            return $parsedSettings;

        } catch (\Throwable $e) {
            $this->logger->error('settings.load_failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * دریافت مقدار یک تنظیم خاص با هوشمندسازی نوع داده
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->load();
        return $all[$key] ?? $default;
    }

    /**
     * ذخیره مقدار جدید برای یک کلید خاص و پاکسازی آنی تمام کش‌ها
     */
    public function set(string $key, string $value): bool
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 255) {
            throw new \InvalidArgumentException('Invalid setting key');
        }

        if (!is_string($value) || strlen($value) > 10000) {
            throw new \InvalidArgumentException('Invalid setting value');
        }

        try {
            $this->db->beginTransaction();

            // 🚀 BUG FIX [H-06]: Pessimistic Locking (SELECT FOR UPDATE)
            // جلوگیری از Race Condition هنگام تغییر تنظیمات حساس توسط چند ادمین
            $this->db->query("SELECT id FROM settings WHERE `key` = ? FOR UPDATE", [$key]);

            $ok = $this->model->set($key, $value);
            
            if ($ok) {
                $this->db->commit();
                $this->clearCache();
                return true;
            }

            $this->db->rollBack();
            return false;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('settings.set_failed', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * ذخیره دسته‌ای تنظیمات و پاکسازی تجمیعی کش
     */
    public function setMany(array $settings): bool
    {
        if (empty($settings)) return true;

        foreach ($settings as $key => $value) {
            if (!is_string($key) || trim($key) === '' || strlen($key) > 255) {
                throw new \InvalidArgumentException('Invalid setting key in batch');
            }

            if (!is_string($value) || strlen($value) > 10000) {
                throw new \InvalidArgumentException('Invalid setting value in batch');
            }
        }

        try {
            $this->db->beginTransaction();

            // 🚀 BUG FIX [H-06]: Locking multiple keys
            $keys = array_keys($settings);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $this->db->query("SELECT id FROM settings WHERE `key` IN ($placeholders) FOR UPDATE", $keys);

            $ok = $this->model->setMany($settings);
            
            if ($ok) {
                $this->db->commit();
                $this->clearCache();
                return true;
            }

            $this->db->rollBack();
            return false;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('settings.set_many_failed', ['keys' => array_keys($settings), 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * بروزرسانی امن مقدار با شناسه (جایگزین کوئری‌های خام قبلی)
     */
    public function updateById(int $id, string $key, string $value): bool
    {
        $record = $this->model->find($id);
        
        // اعتبارسنجی تطابق کلید جهت جلوگیری از بروزرسانی‌های ناخواسته
        if (!$record || (string)($record->key ?? '') !== $key) {
            return false;
        }

        if (!is_string($value) || strlen($value) > 10000) {
            throw new \InvalidArgumentException('Invalid setting value');
        }

        return $this->updateValueById($id, $value);
    }

    /**
     * بروزرسانی مقدار با شناسه مستقیم و پاکسازی کش
     */
    public function updateValueById(int $id, string $value): bool
    {
        $ok = $this->model->updateValueById($id, $value);
        if ($ok) {
            $this->clearCache();
        }
        return $ok;
    }

    /**
     * دریافت تنظیمات تفکیک شده بر اساس دسته‌بندی (مستقیم از مدل)
     */
    public function getByCategory(string $category): array
    {
        return $this->model->getByCategory($category);
    }

    /**
     * جستجوی یک تنظیم کامل بر اساس شناسه
     */
    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    /**
     * جستجوی یک تنظیم کامل بر اساس کلید
     */
    public function findByKey(string $key): ?object
    {
        return $this->model->findByKey($key);
    }

    /**
     * متد پشتیبان بارگذاری همه تنظیمات (همگام‌سازی شده با لودر هوشمند)
     */
    public function loadAll(): array
    {
        return $this->load();
    }

    /**
     * پاک‌سازی فوری تمام لایه‌های کش (دستی و توزیع شده)
     */
    public function clearCache(): void
    {
        $this->runtimeCache = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    // =========================================================================
    // Private Engine
    // =========================================================================

    /**
     * تبدیل هوشمند داده‌های دیتابیس بر اساس تایپ تعریف شده
     */
    private function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = strtolower(trim($type));

        switch ($type) {
            case 'boolean':
            case 'bool':
                // مدیریت دقیق مقادیر متنی رایج برای بولین
                if (in_array(strtolower($value), ['false', '0', 'no', 'off', ''], true)) {
                    return false;
                }
                return true;

            case 'integer':
            case 'int':
                return (int) $value;

            case 'float':
            case 'double':
            case 'numeric':
                return (float) $value;

            case 'json':
            case 'array':
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];

            case 'string':
            default:
                return (string) $value;
        }
    }
}
