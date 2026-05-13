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

if (!function_exists('feature_when_render')) {
    /**
     * Render محتوا فقط اگر فیچر فعال باشد (برای View ها)
     * 
     * استفاده در PHP Views:
     * <?= feature_when_render('crypto_wallet', function() { ?>
     *     <div>کیف پول رمزارز</div>
     * <?php }) ?>
     */
    function feature_when_render(string $feature, callable $callback, ?callable $fallback = null): string
    {
        ob_start();
        
        if (app(\App\Services\FeatureFlagService::class)->isEnabled($feature, user_id())) {
            $callback();
        } elseif ($fallback) {
            $fallback();
        }
        
        return ob_get_clean();
    }
}

if (!function_exists('feature_unless_render')) {
    /**
     * Render محتوا فقط اگر فیچر غیرفعال باشد (برای View ها)
     */
    function feature_unless_render(string $feature, callable $callback): string
    {
        ob_start();
        
        if (!app(\App\Services\FeatureFlagService::class)->isEnabled($feature, user_id())) {
            $callback();
        }
        
        return ob_get_clean();
    }
}

if (!function_exists('feature_css_class')) {
    /**
     * Render کلاس CSS بر اساس فیچر
     */
    function feature_css_class(string $feature, string $enabledClass = 'feature-enabled', string $disabledClass = 'feature-disabled'): string
    {
        return app(\App\Services\FeatureFlagService::class)->isEnabled($feature, user_id()) 
            ? $enabledClass 
            : $disabledClass;
    }
}

if (!function_exists('feature_attribute')) {
    /**
     * Render attribute بر اساس فیچر
     */
    function feature_attribute(string $feature, string $attribute, $value = true): string
    {
        if (!app(\App\Services\FeatureFlagService::class)->isEnabled($feature, user_id())) {
            return '';
        }
        
        if ($value === true) {
            return $attribute;
        }
        
        return sprintf('%s="%s"', $attribute, e($value));
    }
}



