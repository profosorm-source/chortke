<?php

namespace App\Models;

use Core\Model;
use Core\Database;
use Core\Request;
use App\Events\FeatureFlagChanged;
use App\Services\Notification\NotificationService;

/**
 * FeatureFlag Model - Consolidated Version با Redis Support و Targeting پیشرفته
 */
class FeatureFlag extends Model 
{
    private static array $cachedFeatures = [];
    private static bool $loaded = false;
    private array $decodedCache = [];
    private static array $userRoleCache = [];
    private array $requestCache = [];
    
    private \Core\Cache $cache;
    protected \App\Contracts\LoggerInterface $logger;
    private Request $request;
    private ?NotificationService $notificationService;
    private bool $useRedis = false;
    
    private const ALLOWED_UPDATE_FIELDS = [
        'enabled', 'description', 'enabled_percentage',
        'enabled_for_roles', 'enabled_for_users', 'metadata',
        'enabled_from', 'enabled_until', 'depends_on',
        'environments', 'priority', 'tags',
    ];
    
    public function __construct(
        ?Database $db = null, 
        ?\App\Contracts\LoggerInterface $logger = null, 
        ?\Core\Cache $cache = null, 
        ?Request $request = null,
        ?NotificationService $notificationService = null
    )
    {
        parent::__construct($db);
        // M35: Remove Container fallback - require explicit DI or use provided defaults
        $this->logger  = $logger ?? new class implements \App\Contracts\LoggerInterface {
            public function info(string $event, array $context = []): void {}
            public function warning(string $event, array $context = []): void {}
            public function error(string $event, array $context = []): void {}
            public function critical(string $event, array $context = []): void {}
        };
        $this->request = $request ?? new Request();
        $this->notificationService = $notificationService;

        // Initialize Cache (with Redis support if available)
        $this->cache = $cache ?? \Core\Cache::getInstance();
        $this->useRedis = $this->cache->driver() === 'redis';
    }
    
    /**
     * بارگذاری تمام فیچرها
     */
    private function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }
        
        // Try Redis first
        if ($this->useRedis) {
            $cached = $this->cache->get('ff:all_features');
            
            if ($cached !== null) {
                self::$cachedFeatures = $cached;
                self::$loaded = true;
                return;
            }
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
        
        // Cache in Redis
        if ($this->useRedis) {
            $this->cache->set('ff:all_features', self::$cachedFeatures, 3600); // 1 hour
        }
        
        self::$loaded = true;
    }
    
    /**
     * تعداد رکوردهای کش
     */
    public function getCacheCount(): int
    {
        $row = $this->db->fetch("SELECT COUNT(*) as cnt FROM feature_flag_cache");
        return (int)($row->cnt ?? 0);
    }

    /**
     * پاک کردن Cache
     */
    public function clearCache(): void
    {
        // Clear memory cache
        self::$cachedFeatures = [];
        self::$loaded = false;
        $this->decodedCache = [];
        $this->requestCache = [];
        
        // Clear Redis cache
        if ($this->useRedis) {
            $this->cache->delete('ff:all_features');
        }
        
        // Clear Database cache safely using DELETE
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
    
    private function getDecodedRoles(object $feature): array
    {
        $cacheKey = "roles_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->enabled_for_roles 
                ? json_decode($feature->enabled_for_roles, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getDecodedUsers(object $feature): array
    {
        $cacheKey = "users_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->enabled_for_users 
                ? json_decode($feature->enabled_for_users, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getDecodedDependencies(object $feature): array
    {
        $cacheKey = "deps_{$feature->name}";
        
        if (!isset($this->decodedCache[$cacheKey])) {
            $this->decodedCache[$cacheKey] = $feature->depends_on ?? null
                ? json_decode($feature->depends_on, true) ?? []
                : [];
        }
        
        return $this->decodedCache[$cacheKey];
    }
    
    private function getUserRole(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        
        if (!isset(self::$userRoleCache[$userId])) {
            $user = $this->db->fetch("SELECT role FROM users WHERE id = ?", [$userId]);
            self::$userRoleCache[$userId] = $user?->role;
        }
        
        return self::$userRoleCache[$userId];
    }
    
    private function checkTimeSchedule(object $feature): bool
    {
        $now = new \DateTime();
        
        if ($feature->enabled_from ?? null) {
            $enabledFrom = new \DateTime($feature->enabled_from);
            if ($now < $enabledFrom) {
                return false;
            }
        }
        
        if ($feature->enabled_until ?? null) {
            $enabledUntil = new \DateTime($feature->enabled_until);
            if ($now > $enabledUntil) {
                return false;
            }
        }
        
        return true;
    }
    
    private function checkDependencies(string $featureName, ?int $userId = null): bool
    {
        $feature = $this->findByName($featureName);
        if (!$feature) {
            return true;
        }
        
        $dependencies = $this->getDecodedDependencies($feature);
        
        if (empty($dependencies)) {
            return true;
        }
        
        foreach ($dependencies as $depName) {
            if (!$this->isEnabled($depName, $userId)) {
                return false;
            }
        }
        
        return true;
    }
    
    private function checkEnvironment(object $feature): bool
    {
        $environments = $feature->environments ?? null
            ? json_decode($feature->environments, true)
            : null;
        
        if (empty($environments)) {
            return true;
        }
        
        $currentEnv = config('app.env', 'production');
        
        return in_array($currentEnv, $environments, true);
    }
    
    /**
     * بررسی فعال بودن فیچر
     */
    public function isEnabled(string $name, ?int $userId = null, ?string $role = null): bool
    {
        $startTime = microtime(true);
        
        // 1. Request-level cache
        $cacheKey = "{$name}:{$userId}";
        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }
        
        // 2. Redis cache (shared across all instances)
        if ($this->useRedis) {
            $redisKey = "check:{$name}:{$userId}";
            $cached = $this->cache->get($redisKey);
            
            if ($cached !== null) {
                $this->requestCache[$cacheKey] = (bool)$cached;
                return (bool)$cached;
            }
        }
        
        // 3. Database cache (fallback)
        $dbCacheResult = $this->checkDatabaseCache($name, $userId);
        if ($dbCacheResult !== null) {
            $this->requestCache[$cacheKey] = $dbCacheResult;
            
            if ($this->useRedis) {
                $this->cache->set("check:{$name}:{$userId}", $dbCacheResult, 300);
            }
            
            return $dbCacheResult;
        }
        
        // 4. Actual check
        $feature = $this->findByName($name);
        $denyReason = null;
        
        if (!$feature) {
            $result = false;
            $denyReason = 'not_found';
        } elseif (!$feature->enabled) {
            $result = false;
            $denyReason = 'disabled';
        } elseif (!$this->checkTimeSchedule($feature)) {
            $result = false;
            $denyReason = 'time_schedule';
        } elseif (!$this->checkEnvironment($feature)) {
            $result = false;
            $denyReason = 'environment';
        } elseif (!$this->checkDependencies($name, $userId)) {
            $result = false;
            $denyReason = 'dependency';
        } else {
            // Role check
            $allowedRoles = $this->getDecodedRoles($feature);
            if (!empty($allowedRoles)) {
                if ($role === null) {
                    $role = $this->getUserRole($userId);
                }
                
                if (!$role || !in_array($role, $allowedRoles, true)) {
                    $result = false;
                    $denyReason = 'role_denied';
                } else {
                    $result = true;
                }
            } else {
                $result = true;
            }
            
            // User check
            if ($result) {
                $allowedUsers = $this->getDecodedUsers($feature);
                if (!empty($allowedUsers)) {
                    if (!$userId || !in_array($userId, $allowedUsers, true)) {
                        $result = false;
                        $denyReason = 'user_denied';
                    }
                }
            }
            
            // Percentage check
            if ($result && $feature->enabled_percentage < 100) {
                if ($userId) {
                    $hash = \hexdec(\substr(\md5($userId . $name), 0, 8));
                    $userPercentage = ($hash % 100) + 1;
                    
                    if ($userPercentage > $feature->enabled_percentage) {
                        $result = false;
                        $denyReason = 'percentage';
                    }
                } else {
                    $anonId = $this->request->ip();
                    $hash = \hexdec(\substr(\md5($anonId . $name), 0, 8));
                    $userPercentage = ($hash % 100) + 1;
                    
                    if ($userPercentage > $feature->enabled_percentage) {
                        $result = false;
                        $denyReason = 'percentage';
                    }
                }
            }
        }
        
        // Cache results
        $this->requestCache[$cacheKey] = $result;
        
        if ($this->useRedis) {
            $this->cache->set("check:{$name}:{$userId}", $result, 300);
        }
        
        $this->saveToDatabaseCache($name, $userId, $result);
        
        // Metrics
        $responseTime = (microtime(true) - $startTime) * 1000;
        $this->saveMetrics($name, $userId, $result, $denyReason, $responseTime);
        
        return $result;
    }
    
    private function checkDatabaseCache(string $name, ?int $userId): ?bool
    {
        $cacheKey = "{$name}:" . ($userId ?? 'null');
        
        $cached = $this->db->fetch(
            "SELECT is_enabled FROM feature_flag_cache 
             WHERE cache_key = ? AND expires_at > NOW()",
            [$cacheKey]
        );
        
        return $cached ? (bool)$cached->is_enabled : null;
    }
    
    private function saveToDatabaseCache(string $name, ?int $userId, bool $result): void
    {
        $cacheKey = "{$name}:" . ($userId ?? 'null');
        $ttl = 300;
        
        try {
            $sql = "INSERT INTO feature_flag_cache (cache_key, is_enabled, cached_at, expires_at)
                    VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
                    ON DUPLICATE KEY UPDATE 
                        is_enabled = VALUES(is_enabled),
                        cached_at = VALUES(cached_at),
                        expires_at = VALUES(expires_at)";
            
            $this->db->query($sql, [$cacheKey, $result ? 1 : 0, $ttl]);
        } catch (\Exception $e) {
            // Silent fail
        }
    }
    
    private function saveMetrics(string $name, ?int $userId, bool $result, ?string $reason, float $responseTime): void
    {
        try {
            $sql = "INSERT INTO feature_flag_metrics 
                    (feature_name, user_id, check_result, check_reason, checked_at, response_time_ms)
                    VALUES (?, ?, ?, ?, NOW(), ?)";
            
            $this->db->query($sql, [
                $name,
                $userId,
                $result ? 1 : 0,
                $reason,
                round($responseTime, 2),
            ]);
            
            if ($this->useRedis) {
                $this->cache->increment("stats:{$name}:checks");
                if ($result) {
                    $this->cache->increment("stats:{$name}:allowed");
                } else {
                    $this->cache->increment("stats:{$name}:denied");
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }
    }
    
    public function isEnabledForUser(string $name, ?int $userId = null): bool
    {
        return $this->isEnabled($name, $userId);
    }
    
    public function toggle(string $name): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            return false;
        }
        
        $oldValues = ['enabled' => (bool)$feature->enabled];
        $newStatus = !$feature->enabled;
        
        $sql = "UPDATE feature_flags SET enabled = ?, updated_at = NOW() WHERE name = ?";
        $result = $this->db->query($sql, [$newStatus ? 1 : 0, $name]);
        
        if ($result) {
            $this->clearCache();
            
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'toggled',
                $oldValues,
                ['enabled' => $newStatus],
                $this->request->getUser()?->id ?? 0
            ));
        }
        
        return (bool)$result;
    }
    
    public function update(int|string $name, array $data): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            throw new \InvalidArgumentException("Feature '{$name}' not found");
        }
        
        $oldValues = [];
        foreach ($data as $key => $value) {
            if (property_exists($feature, $key)) {
                $oldValues[$key] = $feature->$key;
            }
        }
        
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            if (!in_array($key, self::ALLOWED_UPDATE_FIELDS, true)) {
                throw new \InvalidArgumentException("Invalid field for update: $key");
            }
            
            if (in_array($key, ['enabled_for_roles', 'enabled_for_users', 'metadata', 'depends_on', 'environments', 'tags'])) {
                $value = json_encode($value);
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
            
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'updated',
                $oldValues,
                $data,
                $this->request->getUser()?->id ?? 0
            ));
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
            
            $this->dispatchEvent(new FeatureFlagChanged(
                $data['name'],
                'created',
                [],
                $data,
                $this->request->getUser()?->id ?? 0
            ));
        }
        
        return (bool)$result;
    }
    
    public function delete(int|string $name): bool
    {
        $feature = $this->findByName($name);
        
        if (!$feature) {
            return false;
        }
        
        $oldValues = (array)$feature;
        
        $sql = "DELETE FROM feature_flags WHERE name = ?";
        $result = $this->db->query($sql, [$name]);
        
        if ($result) {
            $this->clearCache();
            
            $this->dispatchEvent(new FeatureFlagChanged(
                $name,
                'deleted',
                $oldValues,
                [],
                $this->request->getUser()?->id ?? 0
            ));
        }
        
        return (bool)$result;
    }
    
    private function dispatchEvent(FeatureFlagChanged $event): void
    {
        try {
            // M35: Only use injected NotificationService, no Container fallback
            if (!$this->notificationService) {
                $this->logger->warning('feature_flag.notification_service_not_available', [
                    'event' => $event->featureName,
                ]);
                return;
            }
            
            $listener = new \App\Listeners\LogFeatureFlagChange($this->db, $this->logger, $this->notificationService);
            $listener->handle($event);
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.event_dispatch_failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
            ]);
        }
    }
    
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
            'redis_enabled' => $this->useRedis,
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
        if ($this->useRedis) {
            $checks = $this->cache->get("stats:{$name}:checks") ?? 0;
            $allowed = $this->cache->get("stats:{$name}:allowed") ?? 0;
            $denied = $this->cache->get("stats:{$name}:denied") ?? 0;
            
            if ($checks > 0) {
                return [[
                    'total_checks' => $checks,
                    'allowed_count' => $allowed,
                    'denied_count' => $denied,
                    'source' => 'redis',
                ]];
            }
        }
        
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