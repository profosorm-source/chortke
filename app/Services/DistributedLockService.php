<?php

namespace App\Services;

use Core\Cache;

use App\Contracts\LoggerInterface;
/**
 * Distributed Lock Service
 * 
 * سرویس قفل توزیع‌شده برای محیط‌های multi-server
 * استفاده از Redis برای اطمینان از اجرای atomic
 */
class DistributedLockService extends \App\Services\BaseService
{
    private Cache $cache;
    private ?bool $useRedis = null;
    private int $defaultTTL = 30; // ثانیه
    
    public function __construct(Cache $cache, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->cache = $cache;
    }
    
    /**
     * اخذ قفل
     * 
     * @param string $resource نام resource که می‌خواهیم قفل کنیم
     * @param int $ttl مدت زمان قفل (ثانیه)
     * @param int $waitTimeout زمان انتظار برای اخذ قفل (ثانیه)
     * @return array ['acquired' => bool, 'token' => string|null]
     */
    public function acquire(string $resource, int $ttl = null, int $waitTimeout = 0): array
    {
        $ttl = $ttl ?? $this->defaultTTL;
        $token = $this->generateToken();
        $key = $this->getLockKey($resource);
        
        $startTime = time();
        
        while (true) {
            // تلاش برای اخذ قفل
            if ($this->tryAcquire($key, $token, $ttl)) {
                return [
                    'acquired' => true,
                    'token' => $token,
                    'expires_at' => time() + $ttl,
                ];
            }
            
            // بررسی timeout
            if (time() - $startTime >= $waitTimeout) {
                return [
                    'acquired' => false,
                    'token' => null,
                    'reason' => 'timeout',
                ];
            }
            
            // صبر کوتاه قبل از تلاش مجدد (100ms)
            usleep(100000);
        }
    }
    
    /**
     * تلاش برای اخذ قفل (atomic)
     */
    private function tryAcquire(string $key, string $token, int $ttl): bool
    {
        if ($this->cache->driver() === 'redis') {
            return $this->tryAcquireRedis($key, $token, $ttl);
        }
        
        // Fallback به file-based lock (برای development)
        return $this->tryAcquireFile($key, $token, $ttl);
    }
    
    /**
     * اخذ قفل با Redis (atomic با SET NX EX)
     */
    private function tryAcquireRedis(string $key, string $token, int $ttl): bool
    {
        $redis = $this->cache->redis();
        
        // استفاده از SET NX EX برای atomic operation
        $result = $redis->set($key, $token, ['NX', 'EX' => $ttl]);
        
        return $result === true;
    }
    
    /**
     * اخذ قفل با File (برای development)
     */
    private function tryAcquireFile(string $key, string $token, int $ttl): bool
    {
        $lockDir = dirname(__DIR__, 2) . '/storage/locks/';
        
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }
        
        $lockFile = $lockDir . md5($key) . '.lock';
        
        // بررسی قفل موجود
        if (file_exists($lockFile)) {
            $data = json_decode(file_get_contents($lockFile), true);
            
            // اگر قفل منقضی شده، حذف کن
            if ($data && $data['expires_at'] < time()) {
                unlink($lockFile);
            } else {
                return false;
            }
        }
        
        // ایجاد قفل جدید
        $data = [
            'token' => $token,
            'expires_at' => time() + $ttl,
            'created_at' => time(),
        ];
        
        file_put_contents($lockFile, json_encode($data));
        
        return true;
    }
    
    /**
     * آزاد کردن قفل
     */
    public function release(string $resource, string $token): bool
    {
        $key = $this->getLockKey($resource);
        
        if ($this->cache->driver() === 'redis') {
            return $this->releaseRedis($key, $token);
        }
        
        return $this->releaseFile($key, $token);
    }
    
    /**
     * آزاد کردن قفل با Redis (با Lua script برای atomic)
     */
    private function releaseRedis(string $key, string $token): bool
    {
        $redis = $this->cache->redis();
        
        // Lua script برای بررسی token و حذف atomic
        $script = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;
        
        $result = $redis->eval($script, [$key, $token], 1);
        
        return $result === 1;
    }
    
    /**
     * آزاد کردن قفل با File
     */
    private function releaseFile(string $key, string $token): bool
    {
        $lockDir = dirname(__DIR__, 2) . '/storage/locks/';
        $lockFile = $lockDir . md5($key) . '.lock';
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        $data = json_decode(file_get_contents($lockFile), true);
        
        // فقط اگر token مطابقت داشته باشد، حذف کن
        if ($data && $data['token'] === $token) {
            unlink($lockFile);
            return true;
        }
        
        return false;
    }
    
    /**
     * تمدید قفل
     */
    public function extend(string $resource, string $token, int $additionalTTL): bool
    {
        $key = $this->getLockKey($resource);
        
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            
            // Lua script برای بررسی token و تمدید
            $script = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("EXPIRE", KEYS[1], ARGV[2])
else
    return 0
end
LUA;
            
            $result = $redis->eval($script, [$key, $token, $additionalTTL], 1);
            
            return $result === 1;
        }
        
        // File-based extend
        $lockDir = dirname(__DIR__, 2) . '/storage/locks/';
        $lockFile = $lockDir . md5($key) . '.lock';
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        $data = json_decode(file_get_contents($lockFile), true);
        
        if ($data && $data['token'] === $token) {
            $data['expires_at'] += $additionalTTL;
            file_put_contents($lockFile, json_encode($data));
            return true;
        }
        
        return false;
    }
    
    /**
     * بررسی وضعیت قفل
     */
    public function isLocked(string $resource): bool
    {
        $key = $this->getLockKey($resource);
        
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            return $redis->exists($key) > 0;
        }
        
        // File-based check
        $lockDir = dirname(__DIR__, 2) . '/storage/locks/';
        $lockFile = $lockDir . md5($key) . '.lock';
        
        if (!file_exists($lockFile)) {
            return false;
        }
        
        $data = json_decode(file_get_contents($lockFile), true);
        
        if ($data && $data['expires_at'] >= time()) {
            return true;
        }
        
        // قفل منقضی شده - حذف کن
        unlink($lockFile);
        return false;
    }
    
    /**
     * اجرای عملیات با قفل
     * 
     * @param string $resource نام resource
     * @param callable $callback تابعی که باید با قفل اجرا شود
     * @param int $ttl مدت قفل
     * @param int $waitTimeout زمان انتظار
     * @return mixed نتیجه callback یا null در صورت عدم موفقیت
     */
    public function synchronized(string $resource, callable $callback, int $ttl = null, int $waitTimeout = 5)
    {
        $lock = $this->acquire($resource, $ttl, $waitTimeout);
        
        if (!$lock['acquired']) {
            throw new \RuntimeException("Failed to acquire lock for resource: {$resource}");
        }
        
        try {
            return $callback();
        } finally {
            $this->release($resource, $lock['token']);
        }
    }
    
    /**
     * تولید token یکتا
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * دریافت کلید کامل قفل
     */
    private function getLockKey(string $resource): string
    {
        $prefix = env('REDIS_PREFIX', 'chortke');
        return $prefix . ':lock:' . $resource;
    }
    
    /**
     * پاکسازی قفل‌های منقضی‌شده (برای file-based locks)
     */
    public function cleanup(): int
    {
        if ($this->cache->driver() === 'redis') {
            return 0; // Redis خودش TTL می‌زنه
        }
        
        $lockDir = dirname(__DIR__, 2) . '/storage/locks/';
        
        if (!is_dir($lockDir)) {
            return 0;
        }
        
        $cleaned = 0;
        $files = glob($lockDir . '*.lock');
        $now = time();
        
        foreach ($files as $file) {
            if (!file_exists($file)) continue;
            
            $data = json_decode(file_get_contents($file), true);
            
            if ($data && $data['expires_at'] < $now) {
                unlink($file);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
    
    /**
     * مثال استفاده:
     * 
     * // روش 1: Manual
     * $lock = $lockService->acquire('payment:user:123', 10);
     * if ($lock['acquired']) {
     *     try {
     *         // انجام عملیات
     *         processPayment($userId);
     *     } finally {
     *         $lockService->release('payment:user:123', $lock['token']);
     *     }
     * }
     * 
     * // روش 2: Synchronized (توصیه می‌شود)
     * $result = $lockService->synchronized('payment:user:123', function() use ($userId) {
     *     return processPayment($userId);
     * });
     */
}

