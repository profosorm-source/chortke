<?php

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * FeatureFlag Model - Pure Data Access Layer
 * 
 * مسئولیت: ذخیره‌سازی و بازیابی داده‌های خام Feature Flags.
 * مدیریت Targeting، HTTP Context، Caching و رویدادها کاملاً در FeatureFlagService انجام می‌شود.
 */
class FeatureFlag extends Model 
{
    private static array $cachedFeatures = [];
    private static bool $loaded = false;
    
    private const ALLOWED_UPDATE_FIELDS = [
        'enabled', 'description', 'enabled_percentage',
        'enabled_for_roles', 'enabled_for_users', 'metadata',
        'enabled_from', 'enabled_until', 'depends_on',
        'environments', 'priority', 'tags',
    ];
    
    public function __construct(Database $db)
    {
        parent::__construct($db);
    }
    
    /**
     * بارگذاری تمام فیچرها در حافظه کش در سطح ریکوئست
     */
    private function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }
        
        $sql = "SELECT * FROM feature_flags ORDER BY name ASC";
        $features = $this->db->fetchAll($sql);
        
        foreach ($features as $feature) {
            $feature->enabled = (bool)($feature->is_enabled ?? $feature->enabled ?? true);
            $feature->enabled_percentage = (int)($feature->rollout_percentage ?? $feature->enabled_percentage ?? 100);
            $feature->enabled_for_users = $feature->allowed_users ?? $feature->enabled_for_users ?? null;
            $feature->enabled_from = $feature->enabled_from ?? null;
            $feature->enabled_until = $feature->enabled_until ?? null;
            $feature->targeted_user_ids = $feature->targeted_user_ids ?? null;
            $feature->targeted_roles = $feature->targeted_roles ?? null;
            $feature->targeted_countries = $feature->targeted_countries ?? null;
            $feature->targeted_plans = $feature->targeted_plans ?? null;
            $feature->targeted_devices = $feature->targeted_devices ?? null;
            $feature->targeted_routes = $feature->targeted_routes ?? null;
            $feature->target_age_min = $feature->target_age_min ?? null;
            $feature->target_age_max = $feature->target_age_max ?? null;
            $feature->percentage_rollout = $feature->percentage_rollout ?? 100;
            self::$cachedFeatures[$feature->name] = $feature;
        }
        
        self::$loaded = true;
    }
    
    /**
     * تعداد رکوردهای کش جدول دیتابیسی
     */
    public function getCacheCount(): int
    {
        $row = $this->db->fetch("SELECT COUNT(*) as cnt FROM feature_flag_cache");
        return (int)($row->cnt ?? 0);
    }

    /**
     * پاک کردن Cache های جدول موقت و رم
     */
    public function clearCache(): void
    {
        self::$cachedFeatures = [];
        self::$loaded = false;
        
        // پاکسازی فیزیکی کش جدول دیتابیس
        $this->db->query("DELETE FROM feature_flag_cache");
    }

    /**
     * پاکسازی Metrics قدیمی
     */
    public function cleanupMetrics(int $days = 30): void
    {
        $sql = "CALL sp_cleanup_feature_metrics(?)";
        $this->db->query($sql, [$days]);
    }
    
    public function getAll(): array
    {
        $this->loadAll();
        return array_values(self::$cachedFeatures);
    }
    
    public function findByName(string $name): ?object
    {
        $this->loadAll();
        return self::$cachedFeatures[$name] ?? null;
    }
    
    public function toggle(string $name): bool
    {
        $feature = $this->findByName($name);
        if (!$feature) {
            return false;
        }
        
        $newStatus = !$feature->enabled;
        
        $sql = "UPDATE feature_flags SET enabled = ?, updated_at = NOW() WHERE name = ?";
        $result = $this->db->query($sql, [$newStatus ? 1 : 0, $name]);
        
        if ($result) {
            $this->clearCache();
        }
        
        return (bool)$result;
    }
    
    public function update(int|string $name, array $data): bool
    {
        $feature = $this->findByName($name);
        if (!$feature) {
            throw new \InvalidArgumentException("Feature '{$name}' not found");
        }
        
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            if (!in_array($key, self::ALLOWED_UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException("Invalid field for update: $key");
            }
            
            if (in_array($key, ['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'])) {
                $value = is_array($value) ? json_encode($value) : $value;
            }
            
            if ($key === 'enabled_percentage') {
                $value = max(0, min(100, (int)$value));
            }
            
            if ($key === 'enabled') {
                $value = $value ? 1 : 0;
            }
            
            $fields[] = "$key = ?";
            $params[] = $value;
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $name;
        
        $sql = "UPDATE feature_flags SET " . implode(', ', $fields) . " WHERE name = ?";
        $result = $this->db->query($sql, $params);
        
        if ($result) {
            $this->clearCache();
        }
        
        return (bool)$result;
    }
    
    public function create(array $data): bool
    {
        $required = ['name', 'description'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Field '$field' is required");
            }
        }
        
        if ($this->findByName($data['name'])) {
            throw new \InvalidArgumentException("Feature '{$data['name']}' already exists");
        }
        
        $defaults = [
            'enabled' => false,
            'enabled_percentage' => 100,
            'enabled_for_roles' => null,
            'enabled_for_users' => null,
            'metadata' => null,
            'enabled_from' => null,
            'enabled_until' => null,
            'depends_on' => null,
            'environments' => null,
            'priority' => 0,
            'tags' => null,
        ];
        
        $data = array_merge($defaults, $data);
        
        foreach (['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'] as $field) {
            if (is_array($data[$field])) {
                $data[$field] = json_encode($data[$field]);
            }
        }
        
        $sql = "INSERT INTO feature_flags 
                (name, description, enabled, enabled_percentage, enabled_for_roles, enabled_for_users, 
                 metadata, enabled_from, enabled_until, depends_on, environments, priority, tags, 
                 created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $result = $this->db->query($sql, [
            $data['name'],
            $data['description'],
            $data['enabled'] ? 1 : 0,
            $data['enabled_percentage'],
            $data['enabled_for_roles'],
            $data['enabled_for_users'],
            $data['metadata'],
            $data['enabled_from'],
            $data['enabled_until'],
            $data['depends_on'],
            $data['environments'],
            $data['priority'],
            $data['tags'],
        ]);
        
        if ($result) {
            $this->clearCache();
        }
        
        return (bool)$result;
    }
    
    public function delete(int|string $name): bool
    {
        $feature = $this->findByName($name);
        if (!$feature) {
            return false;
        }
        
        $sql = "DELETE FROM feature_flags WHERE name = ?";
        $result = $this->db->query($sql, [$name]);
        
        if ($result) {
            $this->clearCache();
        }
        
        return (bool)$result;
    }
    
    /**
     * دریافت آمارهای پایه فیچرتراکینگ در دیتابیس
     */
    public function getStats(): array
    {
        $all = $this->getAll();
        
        $stats = [
            'total' => count($all),
            'enabled' => 0,
            'disabled' => 0,
            'role_restricted' => 0,
            'user_restricted' => 0,
            'percentage_based' => 0,
            'time_scheduled' => 0,
            'with_dependencies' => 0,
        ];
        
        foreach ($all as $feature) {
            if ($feature->enabled) {
                $stats['enabled']++;
            } else {
                $stats['disabled']++;
            }
            
            if ($feature->enabled_for_roles) {
                $stats['role_restricted']++;
            }
            
            if ($feature->enabled_for_users) {
                $stats['user_restricted']++;
            }
            
            if ($feature->enabled_percentage < 100) {
                $stats['percentage_based']++;
            }
            
            if ($feature->enabled_from ?? null || $feature->enabled_until ?? null) {
                $stats['time_scheduled']++;
            }
            
            if ($feature->depends_on ?? null) {
                $stats['with_dependencies']++;
            }
        }
        
        return $stats;
    }
    
    public function getHistory(string $name, int $limit = 50): array
    {
        $sql = "SELECT * FROM feature_flag_history 
                WHERE feature_name = ? 
                ORDER BY changed_at DESC 
                LIMIT ?";
        
        return $this->db->fetchAll($sql, [$name, $limit]) ?: [];
    }
    
    public function getMetrics(string $name, int $hours = 24): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_checks,
                    SUM(check_result) as allowed_count,
                    COUNT(*) - SUM(check_result) as denied_count,
                    AVG(response_time_ms) as avg_response_time,
                    MAX(response_time_ms) as max_response_time,
                    check_reason,
                    COUNT(*) as reason_count
                FROM feature_flag_metrics
                WHERE feature_name = ?
                AND checked_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                GROUP BY check_reason";
        
        return $this->db->fetchAll($sql, [$name, $hours]) ?: [];
    }
    
    public function getConfigValue(string $name, string $key, $default = null)
    {
        $feature = $this->findByName($name);
        
        if (!$feature || !isset($feature->config_values)) {
            return $default;
        }
        
        $config = json_decode($feature->config_values, true);
        
        return $config[$key] ?? $default;
    }
}