<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Rate Limiter Interface
 * 
 * تعریف قراردادی برای Rate Limiting سرویس‌ها
 * از استراتژی‌های مختلف (Fixed Window, Token Bucket, Sliding Window) پشتیبانی می‌کند
 */
interface RateLimiterInterface
{
    /**
     * بررسی و ثبت یک تلاش
     * اگر از حد مجاز تجاوز کرد false برمی‌گرداند
     */
    public function attempt(string $key, ?int $maxAttempts = null, ?int $decayMinutes = null): bool;

    /**
     * دریافت تعداد تلاش‌های فعلی
     */
    public function getAttempts(string $key): int;

    /**
     * alias برای getAttempts
     */
    public function hits(string $key): int;

    /**
     * افزایش شمارنده
     */
    public function incrementAttempts(string $key, int $decayMinutes): void;

    /**
     * حذف کامل کلید (reset)
     */
    public function clear(string $key): void;

    /**
     * ثانیه‌های باقی‌مانده تا ریست
     */
    public function availableIn(string $key): int;

    /**
     * بررسی تلاش‌های ورود
     */
    public function checkLoginAttempt(string $identifier): array;

    /**
     * پاک کردن بعد از ورود موفق
     */
    public function clearLoginAttempts(string $identifier): void;

    /**
     * بررسی لیمیت API
     */
    public function checkApiLimit(int $userId, int $maxRequests = 60, int $perMinutes = 1): array;

    /**
     * پاکسازی فایل‌های منقضی
     */
    public function cleanup(): int;
}
