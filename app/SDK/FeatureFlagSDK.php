<?php

namespace App\SDK;

use App\Services\FeatureFlagService;

/**
 * Feature Flag SDK با Fluent Interface
 * 
 * این SDK استفاده از Feature Flags را بسیار ساده‌تر می‌کند
 */
class FeatureFlagSDK
{
    private FeatureFlagService $featureService;
    private ?int $userId = null;
    private ?string $role = null;
    private array $context = [];
    
    public function __construct(FeatureFlagService $featureService)
    {
        $this->featureService = $featureService;
    }
    
    /**
     * تنظیم کاربر
     */
    public function forUser(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }
    
    /**
     * تنظیم نقش
     */
    public function withRole(string $role): self
    {
        $this->role = $role;
        return $this;
    }
    
    /**
     * تنظیم Context اضافی
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }
    
    /**
     * بررسی فیچر
     */
    public function isEnabled(string $feature): bool
    {
        return $this->featureService->isEnabled($feature, $this->userId, $this->role);
    }
    
    /**
     * بررسی چند فیچر (AND)
     */
    public function allEnabled(array $features): bool
    {
        foreach ($features as $feature) {
            if (!$this->isEnabled($feature)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * بررسی چند فیچر (OR)
     */
    public function anyEnabled(array $features): bool
    {
        foreach ($features as $feature) {
            if ($this->isEnabled($feature)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * اجرای Callback اگر فیچر فعال باشد
     */
    public function when(string $feature, callable $callback, ?callable $fallback = null)
    {
        if ($this->isEnabled($feature)) {
            return $callback($this);
        }
        
        if ($fallback) {
            return $fallback($this);
        }
        
        return null;
    }
    
    /**
     * اجرای Callback اگر فیچر غیرفعال باشد
     */
    public function unless(string $feature, callable $callback)
    {
        if (!$this->isEnabled($feature)) {
            return $callback($this);
        }
        
        return null;
    }
    
    /**
     * دریافت لیست فیچرهای فعال
     */
    public function getEnabled(): array
    {
        $all = $this->featureService->getAll();
        $enabled = [];
        
        foreach ($all as $feature) {
            if ($this->isEnabled($feature->name)) {
                $enabled[] = $feature->name;
            }
        }
        
        return $enabled;
    }
    
    /**
     * بررسی فیچر با Variant
     */
    public function variant(string $feature, array $variants): string
    {
        if (!$this->isEnabled($feature)) {
            return $variants['default'] ?? 'control';
        }
        
        // اگر Percentage-based است، تعیین Variant
        $featureObj = $this->featureService->findByName($feature);
        
        if (!$featureObj) {
            return $variants['default'] ?? 'control';
        }
        
        $percentage = $featureObj->enabled_percentage;
        
        if ($percentage >= 100) {
            return $variants['treatment'] ?? 'treatment';
        }
        
        // تقسیم به Variants
        if ($this->userId) {
            $hash = crc32($this->userId . $feature);
            $userPercentage = ($hash % 100) + 1;
            
            if ($userPercentage <= $percentage) {
                return $variants['treatment'] ?? 'treatment';
            }
        }
        
        return $variants['control'] ?? 'control';
    }
    
    /**
     * بررسی سطح دسترسی مدیریت (🚀 H-03 Fix)
     */
    private function checkAdmin(): void
    {
        if (!\App\Policies\RolePolicy::isAdmin((string)$this->role)) {
            throw new \Core\Exceptions\UnauthorizedException("Access denied: Admin role required for this operation.");
        }
    }

    /**
     * Toggle فیچر (فقط برای Admin)
     */
    public function toggle(string $feature): bool
    {
        $this->checkAdmin();
        return $this->featureService->toggle($feature);
    }
    
    /**
     * Enable فیچر
     */
    public function enable(string $feature): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['enabled' => true]);
    }
    
    /**
     * Disable فیچر
     */
    public function disable(string $feature): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['enabled' => false]);
    }
    
    /**
     * تنظیم Rollout
     */
    public function rollout(string $feature, int $percentage): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['enabled_percentage' => $percentage]);
    }
    
    /**
     * Schedule فیچر
     */
    public function schedule(string $feature, string $from, string $until): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, [
            'enabled_from' => $from,
            'enabled_until' => $until,
        ]);
    }
    
    /**
     * افزودن Dependency
     */
    public function dependsOn(string $feature, array $dependencies): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['depends_on' => $dependencies]);
    }
    
    /**
     * محدود کردن به Environment
     */
    public function limitToEnvironments(string $feature, array $environments): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['environments' => $environments]);
    }
    
    /**
     * محدود کردن به Role
     */
    public function limitToRoles(string $feature, array $roles): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['enabled_for_roles' => $roles]);
    }
    
    /**
     * محدود کردن به Users
     */
    public function limitToUsers(string $feature, array $userIds): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['enabled_for_users' => $userIds]);
    }
    
    /**
     * دریافت Metadata فیچر
     */
    public function getMetadata(string $feature): ?array
    {
        $featureObj = $this->featureService->findByName($feature);
        
        if (!$featureObj || !$featureObj->metadata) {
            return null;
        }
        
        return json_decode($featureObj->metadata, true);
    }
    
    /**
     * تنظیم Metadata
     */
    public function setMetadata(string $feature, array $metadata): bool
    {
        $this->checkAdmin();
        return $this->featureService->update($feature, ['metadata' => $metadata]);
    }
    
    /**
     * دریافت آمار فیچر
     */
    public function stats(string $feature): array
    {
        return $this->featureService->getMetrics($feature, 24);
    }
    
    /**
     * دریافت تاریخچه
     */
    public function history(string $feature, int $limit = 20): array
    {
        return $this->featureService->getHistory($feature, $limit);
    }
    
    /**
     * ایجاد فیچر جدید
     */
    public function create(string $name, string $description, array $options = []): bool
    {
        $this->checkAdmin();
        $data = array_merge([
            'name' => $name,
            'description' => $description,
        ], $options);
        
        return $this->featureService->create($data);
    }
    
    /**
     * حذف فیچر
     */
    public function delete(string $feature): bool
    {
        $this->checkAdmin();
        return $this->featureService->delete($feature);
    }
    
    /**
     * Bulk Operations
     */
    public function bulkEnable(array $features): array
    {
        $this->checkAdmin();
        $results = [];
        
        foreach ($features as $feature) {
            $results[$feature] = $this->enable($feature);
        }
        
        return $results;
    }
    
    public function bulkDisable(array $features): array
    {
        $this->checkAdmin();
        $results = [];
        
        foreach ($features as $feature) {
            $results[$feature] = $this->disable($feature);
        }
        
        return $results;
    }
    
    /**
     * Feature Set Management
     */
    public function enableSet(string $tag): array
    {
        $this->checkAdmin();
        $features = $this->getFeaturesByTag($tag);
        return $this->bulkEnable($features);
    }
    
    public function disableSet(string $tag): array
    {
        $this->checkAdmin();
        $features = $this->getFeaturesByTag($tag);
        return $this->bulkDisable($features);
    }
    
    private function getFeaturesByTag(string $tag): array
    {
        $all = $this->featureService->getAll();
        $result = [];
        
        foreach ($all as $feature) {
            if (!$feature->tags) continue;
            
            $tags = json_decode($feature->tags, true) ?? [];
            if (in_array($tag, $tags)) {
                $result[] = $feature->name;
            }
        }
        
        return $result;
    }
    
    /**
     * تنظیم مجدد Context برای استفاده مجدد
     */
    public function reset(): self
    {
        $this->userId = null;
        $this->role = null;
        $this->context = [];
        return $this;
    }
}

/**
 * Facade برای دسترسی راحت‌تر
 */
class FeatureFlag
{
    private static ?FeatureFlagSDK $instance = null;
    
    public static function instance(): FeatureFlagSDK
    {
        if (self::$instance === null) {
            $service = app(\App\Services\FeatureFlagService::class);
            self::$instance = new FeatureFlagSDK($service);
        }
        
        return self::$instance;
    }
    
    public static function forUser(int $userId): FeatureFlagSDK
    {
        return self::instance()->reset()->forUser($userId);
    }
    
    public static function isEnabled(string $feature): bool
    {
        return self::instance()->isEnabled($feature);
    }
    
    public static function when(string $feature, callable $callback, ?callable $fallback = null)
    {
        return self::instance()->when($feature, $callback, $fallback);
    }
    
    public static function __callStatic($method, $args)
    {
        return self::instance()->$method(...$args);
    }
}
