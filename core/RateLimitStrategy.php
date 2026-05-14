<?php

declare(strict_types=1);

namespace Core;

/**
 * RateLimitStrategy Interface
 *
 * مختلف استراتژی‌های rate limiting را پشتیبانی می‌کند:
 *   - FixedWindow: کلاسیک (پنجره‌های ثابت)
 *   - TokenBucket: توکن‌های دوبازه‌ای
 *   - SlidingWindow: دقیق‌تر (لیست زمانی)
 */
interface RateLimitStrategy
{
    /**
     * بررسی اگر تلاش مجاز است
     *
     * @param string $key کلید rate limit (مثلاً 'login:user@example.com')
     * @param int $maxAttempts حد مجاز تلاش‌ها
     * @param int $decayMinutes بازه زمانی (دقیقه)
     * @return bool true اگر تلاش مجاز است
     */
    public function attempt(string $key, int $maxAttempts, int $decayMinutes): bool;

    /**
     * تعداد تلاش‌های فعلی
     *
     * @param string $key
     * @return int
     */
    public function getAttempts(string $key): int;

    /**
     * ثانیه‌های باقی‌مانده تا ریست
     *
     * @param string $key
     * @return int
     */
    public function availableIn(string $key): int;

    /**
     * ریست کامل
     *
     * @param string $key
     * @return void
     */
    public function clear(string $key): void;

    /**
     * نام استراتژی
     *
     * @return string
     */
    public function getName(): string;
}
