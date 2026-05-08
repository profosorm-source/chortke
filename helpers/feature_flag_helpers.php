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
        static $service;
        static $localCache = [];
        
        if (!$service) {
            $service = app(\App\Services\FeatureFlagService::class);
        }
        
        if ($userId === null) {
            $userId = user_id();
        }
        
        $key = "{$name}:" . ($userId ?? 'guest');
        if (!isset($localCache[$key])) {
            $localCache[$key] = $service->isEnabled($name, $userId);
        }
        
        return $localCache[$key];
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
     * دریافت مقدار از Config یا متادیتای فیچر
     * 
     * @param string $name نام فیچر
     * @param string $key کلید مقدار
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed
     */
    function feature_value(string $name, string $key, $default = null)
    {
        // اول از Config بخون
        $configValue = config("feature_flags.{$name}.{$key}");
        
        if ($configValue !== null) {
            return $configValue;
        }
        
        // اگر نبود، از متادیتای دیتابیس بخون
        static $model;
        if (!$model) {
            $model = app(\App\Models\FeatureFlag::class);
        }
        
        $feature = $model->findByName($name);
        
        if (!$feature || !$feature->metadata) {
            return $default;
        }
        
        $metadata = json_decode($feature->metadata, true);
        
        return $metadata[$key] ?? $default;
    }
}

if (!function_exists('feature_config')) {
    /**
     * دریافت مقدار از config_values فیچر
     * 
     * @param string $name نام فیچر
     * @param string $key کلید مقدار
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed
     */
    function feature_config(string $name, string $key, $default = null)
    {
        static $model;
        if (!$model) {
            $model = app(\App\Models\FeatureFlag::class);
        }
        
        return $model->getConfigValue($name, $key, $default);
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

if (!function_exists('feature_percentage')) {
    /**
     * دریافت درصد فعال‌سازی فیچر
     * 
     * @param string $name
     * @return int
     */
    function feature_percentage(string $name): int
    {
        static $model;
        if (!$model) {
            $model = app(\App\Models\FeatureFlag::class);
        }
        
        $feature = $model->findByName($name);
        
        return $feature ? (int)$feature->enabled_percentage : 0;
    }
}

if (!function_exists('feature_tags')) {
    /**
     * دریافت تگ‌های یک فیچر
     * 
     * @param string $name
     * @return array
     */
    function feature_tags(string $name): array
    {
        static $model;
        if (!$model) {
            $model = app(\App\Models\FeatureFlag::class);
        }
        
        $feature = $model->findByName($name);
        
        if (!$feature || !$feature->tags) {
            return [];
        }
        
        return json_decode($feature->tags, true) ?? [];
    }
}

if (!function_exists('features_by_tag')) {
    /**
     * دریافت فیچرهایی که یک تگ خاص دارند
     * 
     * @param string $tag
     * @return array
     */
    function features_by_tag(string $tag): array
    {
        static $model;
        if (!$model) {
            $model = app(\App\Models\FeatureFlag::class);
        }
        
        $allFeatures = $model->getAll();
        $result = [];
        
        foreach ($allFeatures as $feature) {
            if (!$feature->tags) {
                continue;
            }
            
            $tags = json_decode($feature->tags, true) ?? [];
            
            if (in_array($tag, $tags, true)) {
                $result[] = $feature->name;
            }
        }
        
        return $result;
    }
}

if (!function_exists('feature_history')) {
    /**
     * دریافت تاریخچه تغییرات یک فیچر
     * 
     * @param string $name
     * @param int $limit
     * @return array
     */
    function feature_history(string $name, int $limit = 20): array
    {
        static $model;
        if (!$model) {
            $model = app(\App\Models\FeatureFlag::class);
        }
        
        return $model->getHistory($name, $limit);
    }
}

if (!function_exists('feature_metrics')) {
    /**
     * دریافت متریک‌های یک فیچر
     * 
     * @param string $name
     * @param int $hours
     * @return array
     */
    function feature_metrics(string $name, int $hours = 24): array
    {
        static $model;
        if (!$model) {
            $model = app(\App\Models\FeatureFlag::class);
        }
        
        return $model->getMetrics($name, $hours);
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

