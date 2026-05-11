<?php

namespace App\Services;

/**
 * View Helper برای Feature Flags
 * 
 * این کلاس توابعی برای استفاده در View ها فراهم می‌کند
 */
class FeatureFlagViewHelper
{
    private FeatureFlagService $service;
    
    public function __construct(FeatureFlagService $service)
    {
        $this->service = $service;
    }
    
    /**
     * Render محتوا فقط اگر فیچر فعال باشد
     * 
     * استفاده در PHP Views:
     * <?= $featureHelper->when('crypto_wallet', function() { ?>
     *     <div>کیف پول رمزارز</div>
     * <?php }) ?>
     */
    public function when(string $feature, callable $callback, ?callable $fallback = null): string
    {
        ob_start();
        
        if ($this->service->isEnabled($feature, user_id())) {
            $callback();
        } elseif ($fallback) {
            $fallback();
        }
        
        return ob_get_clean();
    }
    
    /**
     * Render محتوا فقط اگر فیچر غیرفعال باشد
     */
    public function unless(string $feature, callable $callback): string
    {
        ob_start();
        
        if (!$this->service->isEnabled($feature, user_id())) {
            $callback();
        }
        
        return ob_get_clean();
    }
    
    /**
     * بررسی ساده فیچر (برای استفاده در if)
     */
    public function enabled(string $feature): bool
    {
        return $this->service->isEnabled($feature, user_id());
    }
    
    /**
     * دریافت لیست فیچرهای فعال
     */
    public function enabledList(): array
    {
        return $this->service->getEnabled(user_id());
    }
    
    /**
     * Render کلاس CSS بر اساس فیچر
     */
    public function cssClass(string $feature, string $enabledClass = 'feature-enabled', string $disabledClass = 'feature-disabled'): string
    {
        return $this->service->isEnabled($feature, user_id()) 
            ? $enabledClass 
            : $disabledClass;
    }
    
    /**
     * Render attribute بر اساس فیچر
     */
    public function attribute(string $feature, string $attribute, $value = true): string
    {
        if (!$this->service->isEnabled($feature, user_id())) {
            return '';
        }
        
        if ($value === true) {
            return $attribute;
        }
        
        return sprintf('%s="%s"', $attribute, e($value));
    }
}
