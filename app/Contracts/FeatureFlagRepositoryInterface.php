<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Feature Flag Repository Interface
 * 
 * تعریف قراردادی برای مدیریت Feature Flags
 * پشتیبانی می‌کند: targeting پیشرفته، زمان‌بندی، درصد کاربران، caching
 */
interface FeatureFlagRepositoryInterface
{
    /**
     * بررسی آیا یک فیچر برای کاربر خاص فعال است
     * شامل: targeting (role, country, plan, device, route)، زمان‌بندی، درصد کاربران
     */
    public function isEnabled(string $name, ?int $userId = null, ?array $context = null): bool;

    /**
     * بررسی چند فیچر به صورت AND
     */
    public function areEnabled(array $names, ?int $userId = null, ?array $context = null): bool;

    /**
     * دریافت تمام فیچرهای فعال برای کاربر
     */
    public function getEnabled(?int $userId = null, ?array $context = null): array;

    /**
     * دریافت تمام فیچرها
     */
    public function getAll(): array;

    /**
     * یافتن یک فیچر با نام
     */
    public function findByName(string $name): ?object;

    /**
     * دریافت مقدار پارامتر فیچر (برای اعداد dynamic)
     */
    public function getValue(string $name, mixed $default = null): mixed;

    /**
     * فعال کردن یک فیچر
     */
    public function enable(string $name): bool;

    /**
     * غیرفعال کردن یک فیچر
     */
    public function disable(string $name): bool;

    /**
     * پاک کردن cache فیچرها
     */
    public function clearCache(): void;
}
