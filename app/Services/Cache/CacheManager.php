<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Core\Cache;
use App\Contracts\CacheInterface;
use App\Contracts\LoggerInterface;

/**
 * CacheManager - Wrapper استاندارد برای دسترسی به سیستم کش
 * با پشتیبانی کامل از Tags و متدهای الحاقی قرارداد
 */
class CacheManager implements CacheInterface
{
    private array $currentTags = [];

    private Cache $cache;
    private LoggerInterface $logger;
    public function __construct(
        Cache $cache,
        LoggerInterface $logger
    ) {        $this->cache = $cache;
        $this->logger = $logger;
}

    private function getHandler()
    {
        return empty($this->currentTags) ? $this->cache : $this->cache->tags($this->currentTags);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->getHandler()->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttlSeconds = null): bool
    {
        if ($ttlSeconds === null) {
            if (empty($this->currentTags)) {
                return $this->cache->forever($key, $value);
            }
            // TaggedCache doesn't have forever(), emulate with 1 year
            return $this->cache->tags($this->currentTags)->put($key, $value, 525600);
        }

        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        return $this->getHandler()->put($key, $value, $minutes);
    }

    public function delete(string $key): bool
    {
        if (empty($this->currentTags)) {
            return $this->cache->forget($key);
        }
        return $this->getHandler()->forget($key);
    }

    public function increment(string $key, int $step = 1): int
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('increment is not supported on tagged cache.');
        }
        $result = $this->cache->increment($key, $step);
        return $result !== false ? $result : 0;
    }

    public function decrement(string $key, int $step = 1): int
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('decrement is not supported on tagged cache.');
        }
        $result = $this->cache->decrement($key, $step);
        return $result !== false ? $result : 0;
    }

    public function has(string $key): bool
    {
        return $this->getHandler()->has($key);
    }

    public function ttl(string $key): int
    {
        if (!empty($this->currentTags)) {
            throw new \BadMethodCallException('ttl is not supported on tagged cache.');
        }
        return $this->cache->ttl($key);
    }

    public function flush(): bool
    {
        if (empty($this->currentTags)) {
            return $this->cache->flush();
        }
        $this->getHandler()->flush();
        return true;
    }

    public function tags(array $tags): self
    {
        $clone = clone $this;
        $clone->currentTags = $tags;
        return $clone;
    }

    public function remember(string $key, ?int $ttlSeconds, \Closure $callback): mixed
    {
        return $this->getOrSet($key, $callback, $ttlSeconds);
    }

    public function getOrSet(string $key, callable $callback, ?int $ttlSeconds = null): mixed
    {
        if ($ttlSeconds === null) {
            if (empty($this->currentTags)) {
                 return $this->cache->rememberForever($key, $callback);
             }
             $ttlSeconds = 525600 * 60; // 1 year for tagged
        }
        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        return $this->getHandler()->remember($key, $minutes, $callback);
    }

    public function driver(): string
    {
        return $this->cache->driver();
    }

    public function redis(): ?\Redis
    {
        return $this->cache->redis();
    }

    public function redisKey(string $key): string
    {
        // Tags shouldn't affect redisKey directly unless requested, we forward to core Cache
        if (method_exists($this->cache, 'redisKey')) {
            return $this->cache->redisKey($key);
        }
        return $key;
    }
}