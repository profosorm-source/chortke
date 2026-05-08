<?php

declare(strict_types=1);

namespace Core;

use Core\Strategies\FixedWindowStrategy;
use Core\Strategies\TokenBucketStrategy;
use Core\Strategies\SlidingWindowStrategy;

/**
 * RateLimiter — استراتژی‌پذیر و یکپارچه
 *
 * تغییرات نسبت به نسخه قدیم:
 *   ✅ استراتژی‌های مختلف (FixedWindow, TokenBucket, SlidingWindow)
 *   ✅ حذف استفاده مستقیم Redis (فقط از Cache استفاده)
 *   ✅ سازگاری کامل با API قدیم
 *   ✅ راحت‌تر برای تست کردن
 *
 * استفاده:
 *   $rl = new RateLimiter();
 *
 *   // API قدیم (FixedWindow به‌صورت پیش‌فرض)
 *   $rl->attempt('login:user@ex.com', 5, 15);
 *
 *   // با استراتژی مشخص
 *   $rl->setStrategy('token_bucket');
 *   $rl->attempt('api:123', 100, 1);
 */
class RateLimiter
{
    private RateLimitStrategy $strategy;
    private Cache $cache;

    public function __construct(string $strategy = 'fixed_window')
    {
        $this->cache = Cache::getInstance();
        $this->setStrategy($strategy);
    }

    /**
     * استراتژی را تعیین کن
     *
     * @param string $name 'fixed_window' | 'token_bucket' | 'sliding_window'
     */
    public function setStrategy(string $name): self
    {
        $this->strategy = match($name) {
            'fixed_window' => new FixedWindowStrategy(),
            'token_bucket' => new TokenBucketStrategy(),
            'sliding_window' => new SlidingWindowStrategy(),
            default => throw new \InvalidArgumentException("Unknown strategy: $name"),
        };

        return $this;
    }

    /**
     * استراتژی فعلی
     */
    public function getStrategy(): string
    {
        return $this->strategy->getName();
    }

    // ─────────────────────────────────────────────────
    //  عملیات اصلی
    // ─────────────────────────────────────────────────

    /**
     * بررسی اگر تلاش مجاز است
     *
     * @param string $key کلید (مثلاً 'login:user@ex.com')
     * @param int|null $maxAttempts حد مجاز
     * @param int|null $decayMinutes بازه‌ی زمانی
     * @return bool true اگر تلاش مجاز است
     */
    public function attempt(string $key, ?int $maxAttempts = null, ?int $decayMinutes = null): bool
    {
        $maxAttempts = $maxAttempts ?? (int) config('rate_limits.default.max_attempts', 60);
        $decayMinutes = $decayMinutes ?? (int) config('rate_limits.default.decay_minutes', 1);

        return $this->strategy->attempt($key, $maxAttempts, $decayMinutes);
    }

    /**
     * تعداد تلاش‌های فعلی
     */
    public function getAttempts(string $key): int
    {
        return $this->strategy->getAttempts($key);
    }

    /**
     * Alias برای getAttempts
     */
    public function hits(string $key): int
    {
        return $this->getAttempts($key);
    }

    /**
     * ثانیه‌های باقی‌مانده تا ریست
     */
    public function availableIn(string $key): int
    {
        return $this->strategy->availableIn($key);
    }

    /**
     * ریست کامل
     */
    public function clear(string $key): void
    {
        $this->strategy->clear($key);
    }

    // ─────────────────────────────────────────────────
    //  متدهای خاص (شبیه Laravel)
    // ─────────────────────────────────────────────────

    /**
     * بررسی تلاش‌های ورود
     */
    public function checkLoginAttempt(string $identifier): array
    {
        $key = 'login:' . $identifier;

        if (!$this->attempt($key, 5, 15)) {
            $seconds = $this->availableIn($key);
            $minutes = (int) ceil($seconds / 60);

            if (function_exists('logger')) {
                try {
                    logger()->warning('Too many login attempts', [
                        'channel' => 'security',
                        'identifier' => $identifier,
                        'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                    ]);
                } catch (\Throwable $e) {
                    // ignore logger errors
                }
            }

            return [
                'allowed' => false,
                'message' => "تعداد تلاش‌های شما بیش از حد مجاز است. لطفاً {$minutes} دقیقه دیگر امتحان کنید.",
                'retry_after' => $seconds,
            ];
        }

        return ['allowed' => true];
    }

    /**
     * پاک کردن بعد از ورود موفق
     */
    public function clearLoginAttempts(string $identifier): void
    {
        $this->clear('login:' . $identifier);
    }

    /**
     * بررسی لیمیت API
     */
    public function checkApiLimit(int $userId, int $maxRequests = 60, int $perMinutes = 1): array
    {
        $key = 'api:' . $userId;

        if (!$this->attempt($key, $maxRequests, $perMinutes)) {
            return [
                'allowed' => false,
                'message' => 'Too many requests',
                'retry_after' => $this->availableIn($key),
            ];
        }

        return ['allowed' => true];
    }

    /**
     * پاکسازی فایل‌های منقضی
     * (فقط برای حالت فایل مفید است)
     */
    public function cleanup(): int
    {
        if ($this->cache->driver() === 'redis') {
            return 0; // Redis خودش TTL می‌زند
        }

        $cacheDir = __DIR__ . '/../storage/cache/rate_limit/';
        if (!is_dir($cacheDir)) {
            return 0;
        }

        $files = glob($cacheDir . '*.json') ?: [];
        $now = time();
        $cleaned = 0;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $data = json_decode($content, true);
            if ($data && isset($data['expire_at']) && $data['expire_at'] < $now) {
                unlink($file);
                $cleaned++;
            }
        }

        if (function_exists('logger')) {
            try {
                logger()->info('rate_limit.cleanup.completed', [
                    'channel' => 'security',
                    'cleaned' => $cleaned,
                ]);
            } catch (\Throwable $e) {
                // ignore logger errors
            }
        }

        return $cleaned;
    }
}
