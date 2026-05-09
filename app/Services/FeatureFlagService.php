<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeatureFlag;
use App\Contracts\FeatureFlagRepositoryInterface;
use Core\Cache;
use App\Contracts\LoggerInterface;

/**
 * Feature Flag Service
 * 
 * مدیریت Feature Flags با targeting پیشرفته
 * شامل: user targeting، role، کشور، پلن، device، route، age، percentage rollout
 */
class FeatureFlagService extends \App\Services\BaseService
{
    private \Core\Database $db;
    private FeatureFlag $featureModel;
    private Cache $cache;
    
    public function __construct(
        FeatureFlag $featureModel,
        \Core\Database $db,
        Cache $cache,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->featureModel = $featureModel;
        $this->db = $db;
        $this->cache = $cache;
    }
    
    /**
     * بررسی آیا یک فیچر برای کاربر فعال است
     * شامل: targeting، زمان‌بندی، درصد کاربران
     */
    public function isEnabled(string $name, ?int $userId = null, ?array $context = null): bool
    {
        // cache check
        $cacheKey = "ff:enabled:{$name}:{$userId}";
        if ($cached = $this->cache->get($cacheKey)) {
            return (bool)$cached;
        }

        try {
            $feature = $this->featureModel->findByName($name);
        } catch (\Throwable $e) {
            $feature = null;
        }

        if (!$feature) {
            $fallbackEnabled = config("feature_flags.{$name}.enabled");
            if ($fallbackEnabled !== null) {
                return (bool)$fallbackEnabled;
            }
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        if (!$feature->enabled) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        // بررسی زمان‌بندی
        if (!$this->checkTimeSchedule($feature)) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        // اگر user id نیست، فقط check عمومی
        if (!$userId) {
            $result = (bool)$feature->enabled;
            $this->cache->put($cacheKey, $result ? 1 : 0, 5);
            return $result;
        }

        // دریافت اطلاعات کاربر
        $userContext = $this->getUserContext($userId, $context);
        
        // بررسی targeting
        if (!$this->checkTargeting($feature, $userContext)) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        // بررسی percentage rollout (consistent و reproducible)
        if (!$this->checkPercentageRollout($feature, $userId)) {
            $this->cache->put($cacheKey, 0, 5);
            return false;
        }

        $this->cache->put($cacheKey, 1, 5);
        return true;
    }

    /**
     * بررسی چند فیچر به صورت AND
     */
    public function areEnabled(array $names, ?int $userId = null, ?array $context = null): bool
    {
        foreach ($names as $name) {
            if (!$this->isEnabled($name, $userId, $context)) {
                return false;
            }
        }
        return true;
    }

    /**
     * دریافت تمام فیچرهای فعال برای کاربر
     */
    public function getEnabled(?int $userId = null, ?array $context = null): array
    {
        $all = $this->featureModel->getAll();
        $enabled = [];
        
        foreach ($all as $feature) {
            if ($this->isEnabled($feature->name, $userId, $context)) {
                $enabled[] = $feature->name;
            }
        }
        
        return $enabled;
    }

    /**
     * دریافت مقدار پارامتر فیچر (برای اعداد dynamic)
     * مثال: getValue('lottery_profit_percentage', 10) => 15
     */
    public function getValue(string $name, mixed $default = null): mixed
    {
        $feature = $this->featureModel->findByName($name);
        if (!$feature || !$feature->config_values) {
            return $default;
        }

        $config = json_decode($feature->config_values, true) ?? [];
        return $config;
    }

    /**
     * دریافت یک پارامتر خاص از feature flag
     */
    public function getConfig(string $featureName, string $configKey, mixed $default = null): mixed
    {
        $config = $this->getValue($featureName);
        if (is_array($config) && isset($config[$configKey])) {
            return $config[$configKey];
        }
        
        // بازگشت به فایل کانفیگ به عنوان Fallback لایه زیرساخت
        $configValue = config("feature_flags.{$featureName}.{$configKey}");
        if ($configValue !== null) {
            return $configValue;
        }
        
        return $default;
    }

    /**
     * بررسی targeting پیشرفته
     */
    private function checkTargeting(object $feature, array $userContext): bool
    {
        // بررسی user_ids خاص
        if ($feature->targeted_user_ids) {
            $userIds = json_decode($feature->targeted_user_ids, true) ?? [];
            if (!empty($userIds) && !in_array($userContext['user_id'], $userIds)) {
                return false;
            }
        }

        // بررسی roles
        if ($feature->targeted_roles) {
            $roles = json_decode($feature->targeted_roles, true) ?? [];
            if (!empty($roles) && !in_array($userContext['role'], $roles)) {
                return false;
            }
        }

        // بررسی کشورها
        if ($feature->targeted_countries) {
            $countries = json_decode($feature->targeted_countries, true) ?? [];
            if (!empty($countries) && !in_array($userContext['country'] ?? null, $countries)) {
                return false;
            }
        }

        // بررسی پلن‌ها
        if ($feature->targeted_plans) {
            $plans = json_decode($feature->targeted_plans, true) ?? [];
            if (!empty($plans) && !in_array($userContext['plan'] ?? null, $plans)) {
                return false;
            }
        }

        // بررسی devices
        if ($feature->targeted_devices) {
            $devices = json_decode($feature->targeted_devices, true) ?? [];
            if (!empty($devices) && !in_array($userContext['device'] ?? null, $devices)) {
                return false;
            }
        }

        // بررسی routes
        if ($feature->targeted_routes) {
            $routes = json_decode($feature->targeted_routes, true) ?? [];
            $currentRoute = $userContext['route'] ?? ($_SERVER['REQUEST_URI'] ?? null);
            
            if (!empty($routes) && $currentRoute !== null) {
                $match = false;
                foreach ($routes as $route) {
                    // تطابق دقیق یا تطابق کامل پیشوند پوشه برای تضمین امنیت و عدم دور زدن مسیرها
                    if ($currentRoute === $route || strpos($currentRoute, $route . '/') === 0 || strpos($currentRoute, $route . '?') === 0) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) return false;
            }
        }

        // بررسی age
        if ($feature->target_age_min || $feature->target_age_max) {
            $age = $userContext['age'] ?? null;
            if ($age) {
                if ($feature->target_age_min && $age < $feature->target_age_min) return false;
                if ($feature->target_age_max && $age > $feature->target_age_max) return false;
            }
        }

        return true;
    }

    /**
     * بررسی percentage rollout (consistent عبر sessions)
     * استفاده از hash برای consistency
     */
    private function checkPercentageRollout(object $feature, int $userId): bool
    {
        if (!isset($feature->percentage_rollout) || $feature->percentage_rollout >= 100) {
            return true;
        }

        // استفاده از seed برای consistency
        $seed = $feature->rollout_seed ?? $feature->name;
        $hash = hexdec(substr(hash('sha256', "{$userId}:{$seed}"), 0, 8)) % 100;
        
        return $hash < (int)$feature->percentage_rollout;
    }

    /**
     * بررسی زمان‌بندی (time schedule)
     */
    private function checkTimeSchedule(object $feature): bool
    {
        $now = date('Y-m-d H:i:s');
        
        if ($feature->enabled_from && $now < $feature->enabled_from) {
            return false;
        }
        
        if ($feature->enabled_until && $now > $feature->enabled_until) {
            return false;
        }
        
        return true;
    }

    /**
     * دریافت اطلاعات کاربر برای targeting
     */
    private function getUserContext(int $userId, ?array $context = null): array
    {
        if ($context) {
            return array_merge(['user_id' => $userId], $context);
        }

        // دریافت از database
        $sql = "
            SELECT 
                u.id as user_id,
                u.role
            FROM users u
            WHERE u.id = ?
            LIMIT 1
        ";
        
        $user = $this->db->fetch($sql, [$userId]);
        if (!$user) {
            return ['user_id' => $userId, 'role' => 'user'];
        }

        $plan = null;
        $device = null;
        try {
            $kyc = $this->db->fetch("SELECT plan, device_type FROM kyc_verifications WHERE user_id = ? LIMIT 1", [$userId]);
            if ($kyc) {
                $plan = $kyc->plan ?? null;
                $device = $kyc->device_type ?? null;
            }
        } catch (\Throwable $e) {
            // Ignore if table or columns don't exist
        }

        return [
            'user_id' => $userId,
            'role' => $user->role ?? 'user',
            'country' => 'IR',
            'plan' => $plan,
            'device' => $device,
            'age' => null,
            'route' => $_SERVER['REQUEST_URI'] ?? '/'
        ];
    }

    /**
     * محاسبه سن از birth date
     */
    private function calculateAge(string $birthDate): int
    {
        $birth = new \DateTime($birthDate);
        $today = new \DateTime();
        return $today->diff($birth)->y;
    }

    /**
     * پاک کردن cache
     */
    public function clearCache(string $featureName = null): void
    {
        if ($featureName) {
            $this->cache->tags(['feature_flag'])->forget($featureName);
        } else {
            $this->cache->tags(['feature_flag'])->flush();
        }
    }

    /**
     * دریافت تمام فیچرها
     */
    public function getAll(): array
    {
        return $this->featureModel->getAll();
    }

    /**
     * یافتن یک فیچر با نام
     */
    public function findByName(string $name): ?object
    {
        return $this->featureModel->findByName($name);
    }

    public function toggle(string $name): bool
    {
        $result = $this->featureModel->toggle($name);
        if ($result) {
            $this->clearCache($name);
        }
        return $result;
    }

    public function update(string $name, array $data): bool
    {
        $result = $this->featureModel->update($name, $data);
        if ($result) {
            $this->clearCache($name);
        }
        return $result;
    }

    public function create(array $data): bool
    {
        $result = $this->featureModel->create($data);
        if ($result) {
            $this->clearCache();
        }
        return $result;
    }

    public function delete(string $name): bool
    {
        $result = $this->featureModel->delete($name);
        if ($result) {
            $this->clearCache();
        }
        return $result;
    }

    public function getStats(): array
    {
        return $this->featureModel->getStats();
    }

    public function getHistory(string $name): array
    {
        return $this->featureModel->getHistory($name);
    }

    public function getMetrics(string $name): array
    {
        return $this->featureModel->getMetrics($name);
    }
}


