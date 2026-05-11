<?php

/**
 * Feature Flag Helper Functions
 * 
 * این توابع برای استفاده راحت‌تر از Feature Flags در سراسر برنامه
 */

if (!function_exists('feature_enabled')) {
    /**
     * بررسی فعال بودن یک فیچر
     * 
     * @param string $name نام فیچر
     * @param int|null $userId آیدی کاربر (اگر null باشد، کاربر فعلی استفاده می‌شود)
     * @return bool
     */
    function feature_enabled(string $name, ?int $userId = null): bool
    {
        return app(\App\Services\FeatureFlagService::class)
            ->isEnabled($name, $userId ?? user_id());
    }
}

if (!function_exists('features_enabled')) {
    /**
     * بررسی فعال بودن چندین فیچر (AND logic)
     * 
     * @param array $names آرایه نام فیچرها
     * @param int|null $userId
     * @return bool
     */
    function features_enabled(array $names, ?int $userId = null): bool
    {
        foreach ($names as $name) {
            if (!feature_enabled($name, $userId)) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('any_feature_enabled')) {
    /**
     * بررسی فعال بودن حداقل یکی از فیچرها (OR logic)
     * 
     * @param array $names
     * @param int|null $userId
     * @return bool
     */
    function any_feature_enabled(array $names, ?int $userId = null): bool
    {
        foreach ($names as $name) {
            if (feature_enabled($name, $userId)) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('when_feature')) {
    /**
     * اجرای کد فقط وقتی فیچر فعال باشد
     * 
     * @param string $name
     * @param callable $callback
     * @param callable|null $fallback
     * @return mixed
     */
    function when_feature(string $name, callable $callback, ?callable $fallback = null)
    {
        if (feature_enabled($name)) {
            return $callback();
        }
        
        if ($fallback) {
            return $fallback();
        }
        
        return null;
    }
}

if (!function_exists('unless_feature')) {
    /**
     * اجرای کد فقط وقتی فیچر غیرفعال باشد
     * 
     * @param string $name
     * @param callable $callback
     * @return mixed
     */
    function unless_feature(string $name, callable $callback)
    {
        if (!feature_enabled($name)) {
            return $callback();
        }
        
        return null;
    }
}

if (!function_exists('feature_value')) {
    /**
     * دریافت مقدار از Feature Flag با بازگشت به Config به عنوان Fallback
     * 
     * @param string $name نام فیچر
     * @param string $key کلید مقدار
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed
     */
    function feature_value(string $name, string $key, $default = null)
    {
        return app(\App\Services\FeatureFlagService::class)->getConfig($name, $key, $default);
    }
}

if (!function_exists('feature_config')) {
    /**
     * دریافت مقدار از پیکربندی فیچر (نام مستعار)
     * 
     * @param string $name نام فیچر
     * @param string $key کلید مقدار
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed
     */
    function feature_config(string $name, string $key, $default = null)
    {
        return app(\App\Services\FeatureFlagService::class)->getConfig($name, $key, $default);
    }
}

if (!function_exists('enabled_features')) {
    /**
     * دریافت لیست فیچرهای فعال برای کاربر
     * 
     * @param int|null $userId
     * @return array
     */
    function enabled_features(?int $userId = null): array
    {
        static $service;
        
        if (!$service) {
            $service = app(\App\Services\FeatureFlagService::class);
        }
        
        if ($userId === null) {
            $userId = user_id();
        }
        
        return $service->getEnabled($userId);
    }
}


if (!function_exists('feature')) {
    /**
     * بررسی فعال بودن یک فیچر (legacy wrapper)
     */
    function feature(string $name, ?int $userId = null): bool
    {
        return feature_enabled($name, $userId);
    }
}

