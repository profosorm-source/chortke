<?php

declare(strict_types=1);

namespace Core\Strategies;

use Core\Cache;
use Core\RateLimitStrategy;

/**
 * SlidingWindowStrategy — استراتژی پنجره‌ی لغزنده
 *
 * کیفیت: دقیق‌ترین
 * نقطه ضعف: بیشترین حافظه (لیست تمام timestamps)
 *
 * نحوه کار:
 *   - تمام timestamps درخواست‌ها را ذخیره می‌کند
 *   - درخواست‌های قدیم‌تر از window حذف می‌شوند
 *   - اگر تعداد <= maxAttempts، اجازه می‌دهد
 *
 * بهترین برای: صورت‌حساب‌های دقیق، audit logging
 */
class SlidingWindowStrategy implements RateLimitStrategy
{
    private Cache $cache;
    private string $prefix = 'rl:sw:';

    public function __construct()
    {
        $this->cache = Cache::getInstance();
    }

    public function attempt(string $key, int $maxAttempts, int $decayMinutes): bool
    {
        $cacheKey = $this->prefix . $key;
        $now = time();
        $windowStart = $now - ($decayMinutes * 60);

        // لیست timestamps را دریافت کن
        $timestamps = $this->cache->get($cacheKey, []);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // timestamps قدیم‌تر از window را حذف کن
        $timestamps = array_filter(
            $timestamps,
            fn($ts) => $ts > $windowStart
        );

        // تعداد درخواست‌های معتبر
        $count = count($timestamps);

        if ($count >= $maxAttempts) {
            return false;
        }

        // timestamp جدید را اضافه کن
        $timestamps[] = $now;

        // ذخیره کن
        $this->cache->put($cacheKey, $timestamps, $decayMinutes);

        return true;
    }

    public function getAttempts(string $key): int
    {
        $cacheKey = $this->prefix . $key;
        $timestamps = $this->cache->get($cacheKey, []);

        if (!is_array($timestamps)) {
            return 0;
        }

        return count($timestamps);
    }

    public function availableIn(string $key): int
    {
        $cacheKey = $this->prefix . $key;
        $timestamps = $this->cache->get($cacheKey, []);

        if (empty($timestamps) || !is_array($timestamps)) {
            return 0;
        }

        // آخرین timestamp
        $oldest = min($timestamps);
        $ttl = $this->cache->ttl($cacheKey);

        return max(0, $ttl);
    }

    public function clear(string $key): void
    {
        $cacheKey = $this->prefix . $key;
        $this->cache->forget($cacheKey);
    }

    public function getName(): string
    {
        return 'sliding_window';
    }
}
