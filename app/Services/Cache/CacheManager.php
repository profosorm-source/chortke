<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Contracts\CacheInterface;
use Core\Cache;
use App\Contracts\LoggerInterface;

/**
 * Cache Manager (Contracts Implementation)
 * 
 * Wrapper برای Core\Cache
 * Implementation از CacheInterface برای DI و تست‌پذیری
 * 
 * این سرویس Core\Cache را wrap می‌کند و بهتر abstraction فراهم می‌کند
 */
class CacheManager extends \App\Services\BaseService implements CacheInterface
{
    private Cache $cache;


    public function __construct(Cache $cache, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->cache = $cache;
    }

    /**
     * دریافت از cache
     */
    public function get(string $key, $default = null)
    {
        try {
            return $this->cache->get($key, $default);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.get.failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $default;
        }
    }

    /**
     * ذخیره در cache
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        try {
            // convert seconds to minutes (Core\Cache expects minutes)
            if ($ttl !== null && $ttl <= 0) {
                return $this->delete($key);
            }
            $minutes = $ttl !== null ? (int)\max(1, \ceil($ttl / 60)) : 60;
            return $this->cache->put($key, $value, $minutes);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.set.failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * حذف از cache
     */
    public function delete(string $key): bool
    {
        try {
            return $this->cache->forget($key);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.delete.failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * افزایش (increment)
     */
    public function increment(string $key, int $step = 1): int
    {
        try {
            $result = $this->cache->increment($key, $step);
            return (int)($result ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.increment.failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * دریافت یا set (remember)
     */
    public function getOrSet(string $key, callable $callback, ?int $ttl = null)
    {
        try {
            if ($ttl !== null && $ttl <= 0) {
                $this->delete($key);
                return $callback();
            }
            $minutes = $ttl !== null ? (int)\max(1, \ceil($ttl / 60)) : 60;
            return $this->cache->remember($key, $minutes, $callback);
        } catch (\Throwable $e) {
            $this->logger->warning('cache.remember.failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }
    }

    /**
     * دریافت TTL باقی‌مانده
     */
    public function ttl(string $key): int
    {
        try {
            return $this->cache->ttl($key);
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /**
     * بررسی وجود
     */
    public function has(string $key): bool
    {
        try {
            return $this->cache->has($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * فلاش کامل cache
     */
    public function flush(): bool
    {
        try {
            return $this->cache->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('cache.flush.failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * دسترسی به cache اصلی برای عملیات پیشرفته
     */
    public function getCache(): Cache
    {
        return $this->cache;
    }

    /**
     * دسترسی به Redis مستقیم (برای عملیات خاص)
     */
    public function redis(): ?\Redis
    {
        return $this->cache->redis();
    }

    /**
     * Driver فعلی: redis یا file
     */
    public function driver(): string
    {
        return $this->cache->driver();
    }

    /**
     * Tagged cache برای invalidation گروهی
     */
    public function tags(array $tags): \Core\TaggedCache
    {
        try {
            if (empty($tags)) {
                throw new \InvalidArgumentException('Tags array cannot be empty.');
            }
            return $this->cache->tags($tags);
        } catch (\Throwable $e) {
            $this->logger->error('cache.tags.failed', [
                'tags' => $tags,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
