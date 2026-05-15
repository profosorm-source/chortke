<?php

declare(strict_types=1);

namespace Core\Strategies;

use Core\Cache;
use Core\RateLimitStrategy;

/**
 * TokenBucketStrategy — استراتژی توکن‌های دوبازه‌ای
 *
 * کیفیت: عادل (بدون edge case)
 * استفاده: بهتر برای API rate limiting
 *
 * نحوه کار:
 *   - هر درخواست یک توکن می‌خورد
 *   - توکن‌ها با نرخ ثابت دوبازه‌ای تولید می‌شوند
 *   - مثال: 100 request در دقیقه = 100/60 ~= 1.67 token/second
 *
 * ذخیره‌سازی:
 *   - {count}: تعداد توکن‌های باقی‌مانده
 *   - {timestamp}: آخرین زمان به‌روز‌رسانی
 */
class TokenBucketStrategy implements RateLimitStrategy
{
    private Cache $cache;
    private string $prefix = 'rl:tb:';

    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    public function attempt(string $key, int $maxAttempts, int $decayMinutes): bool
    {
        $cacheKey = $this->prefix . $key;
        
        // CORE-041: Wrap TokenBucket processing inside atomic lock
        $result = $this->cache->withLock('lock:' . $cacheKey, function() use ($cacheKey, $maxAttempts, $decayMinutes) {
            $bucket = $this->getBucket($cacheKey, $maxAttempts, $decayMinutes);

            if ($bucket['tokens'] <= 0) {
                return false;
            }

            // کاهش یک توکن
            $bucket['tokens']--;
            $bucket['updated_at'] = time();

            $this->cache->forever($cacheKey, $bucket);

            return true;
        }, 5);

        return $result === true;
    }

    public function getAttempts(string $key): int
    {
        $cacheKey = $this->prefix . $key;
        $bucket = $this->cache->get($cacheKey);

        if (!is_array($bucket)) {
            return 0;
        }

        return (int) ($bucket['max_tokens'] - $bucket['tokens']);
    }

    public function availableIn(string $key): int
    {
        $cacheKey = $this->prefix . $key;
        $bucket = $this->cache->get($cacheKey);

        if (!is_array($bucket) || !isset($bucket['expire_at'])) {
            return 0;
        }

        $left = $bucket['expire_at'] - time();
        return max(0, $left);
    }

    public function clear(string $key): void
    {
        $cacheKey = $this->prefix . $key;
        $this->cache->forget($cacheKey);
    }

    public function getName(): string
    {
        return 'token_bucket';
    }

    /**
     * Bucket فعلی را دریافت می‌کند و توکن‌ها را دوبازه‌ای تولید می‌کند
     */
    private function getBucket(string $cacheKey, int $maxTokens, int $decayMinutes): array
    {
        $stored = $this->cache->get($cacheKey);

        if (!is_array($stored) || !isset($stored['tokens'])) {
            // Bucket جدید
            return [
                'max_tokens' => $maxTokens,
                'tokens' => $maxTokens, // Will be decremented in attempt()
                'updated_at' => time(),
                'expire_at' => time() + ($decayMinutes * 60),
            ];
        }

        // Bucket موجود — توکن‌ها را دوبازه‌ای تولید کن
        $now = time();
        $elapsed = $now - $stored['updated_at'];
        $decaySeconds = $decayMinutes * 60;

        // نرخ تولید: maxTokens / decaySeconds
        $tokensPerSecond = $stored['max_tokens'] / $decaySeconds;
        $newTokens = $elapsed * $tokensPerSecond;

        $tokens = (int) min(
            $stored['max_tokens'],
            $stored['tokens'] + $newTokens
        );

        return [
            'max_tokens' => $stored['max_tokens'],
            'tokens' => $tokens,
            'updated_at' => $now,
            'expire_at' => $stored['expire_at'],
        ];
    }
}
