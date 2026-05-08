<?php

declare(strict_types=1);

namespace Core\Strategies;

use Core\Cache;
use Core\RateLimitStrategy;

/**
 * FixedWindowStrategy — استراتژی کلاسیک
 *
 * کیفیت: سادهSplittedو سریع
 * نقطه ضعف: edge case در مرز پنجره‌ها
 *
 * نحوه کار:
 *   - شمارنده‌ای برای هر پنجره
 *   - پس از انقضای پنجره، ریست می‌شود
 *   - Atomic increment در Redis برای جلوگیری از race condition
 */
class FixedWindowStrategy implements RateLimitStrategy
{
    private Cache $cache;
    private string $prefix = 'rl:fw:';

    public function __construct()
    {
        $this->cache = Cache::getInstance();
    }

    public function attempt(string $key, int $maxAttempts, int $decayMinutes): bool
    {
        $cacheKey = $this->prefix . $key;
        $attempts = $this->getAttempts($key);

        if ($attempts >= $maxAttempts) {
            return false;
        }

        // فقط در Redis می‌توانیم atomic increment داشته باشیم
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            $rKey = $this->getRedisKey($cacheKey);
            $ttl = $decayMinutes * 60;

            $script = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
  redis.call('EXPIRE', KEYS[1], ARGV[1])
end
if current > tonumber(ARGV[2]) then
  return 0
end
return 1
LUA;

            $allowed = (int) $redis->eval($script, [$rKey, $ttl, $maxAttempts], 1);
            return $allowed === 1;
        }

        // File fallback
        $current = (int) $this->cache->get($cacheKey, 0);
        if ($current >= $maxAttempts) {
            return false;
        }

        $next = $current + 1;
        $this->cache->put($cacheKey, $next, $decayMinutes);
        return true;
    }

    public function getAttempts(string $key): int
    {
        $cacheKey = $this->prefix . $key;
        $value = $this->cache->get($cacheKey, 0);
        return (int) $value;
    }

    public function availableIn(string $key): int
    {
        $cacheKey = $this->prefix . $key;
        return $this->cache->ttl($cacheKey);
    }

    public function clear(string $key): void
    {
        $cacheKey = $this->prefix . $key;
        $this->cache->forget($cacheKey);
    }

    public function getName(): string
    {
        return 'fixed_window';
    }

    private function getRedisKey(string $key): string
    {
        $prefix = env('REDIS_PREFIX', 'chortke');
        return $prefix . ':' . $key;
    }
}
