<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Core\Cache;
use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;

/**
 * CacheManager - Wrapper استاندارد برای دسترسی به سیستم کش
 * رفع باگ TTL: تمامی مقادیر به ثانیه منتقل می‌شوند.
 */
class CacheManager implements CacheInterface
{
    public function __construct(
        private Cache $cache,
        private LoggerInterface $logger
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($key, $default);
    }

    /**
     * ذخیره در کش
     * @param int|null $ttl زمان به ثانیه (FIX: قبلاً به دقیقه تبدیل می‌شد که اشتباه بود)
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->cache->forever($key, $value);
        }

        // استفاده مستقیم از ثانیه برای سازگاری با استاندارد PSR-16
        return $this->cache->putSeconds($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->forget($key);
    }

    public function flush(): bool
    {
        return $this->cache->flush();
    }

    /**
     * پشتیبانی از تگ‌ها (حتی در درایور فایل با مکانیزم شبیه‌سازی)
     */
    public function tags(array $tags): self
    {
        $this->cache->tags($tags);
        return $this;
    }

    public function remember(string $key, ?int $ttl, \Closure $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
    
    public function driver(): string { return $this->cache->getDriver(); }
    public function redis(): ?\Redis { return $this->cache->redis(); }
    public function redisKey(string $key): string { return $this->cache->redisKey($key); }
}