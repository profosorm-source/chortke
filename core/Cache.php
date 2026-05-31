<?php

declare(strict_types=1);
namespace Core;

/**
 * Cache System — Redis + File Fallback
 *
 * اگر Redis موجود باشد، از آن استفاده می‌کند؛
 * در غیر این صورت به‌صورت خودکار به کش فایلی
 * (همان رفتار قدیمی) سوئیچ می‌کند.
 *
 * متدها:
 *   put($key, $value, $minutes)
 *   get($key, $default)
 *   has($key)
 *   forget($key)
 *   flush()
 *   remember($key, $minutes, callable)
 *   rememberForever($key, callable)
 *   forever($key, $value)
 *   increment($key, $step)
 *   decrement($key, $step)
 *   ttl($key)             → ثانیه‌های باقی‌مانده
 *   tags(array $tags)     → TaggedCache
 *   cleanup()             → فقط در حالت فایل
 *   driver()              → 'redis' | 'file'
 */
class Cache
{
    private static ?self $instance = null;

    private string $driver = 'file';
    private ?\Redis $redis = null;
    private string $redisPrefix = 'chortke:';

    private string $cacheDir;
    private array $fileLocks = [];
    private array $redisLocks = [];

    // ─────────────────────────────────────────────────
    //  Bootstrap
    // ─────────────────────────────────────────────────

    private function __construct()
    {
        $this->cacheDir = __DIR__ . '/../storage/cache/app/';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $this->tryConnectRedis();
    }

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public static function reset(): void
    {
        if (static::$instance !== null) {
            static::$instance->flushAllLocks();
        }
        static::$instance = null;
    }

    public function __destruct()
    {
        $this->flushAllLocks();
    }

    /** نوع درایور فعال: 'redis' یا 'file' */
    public function driver(): string
    {
        return $this->driver;
    }

    public function safeUnserialize($raw, array $allowedClasses = [\stdClass::class])
    {
        if ($raw === null || $raw === false) {
            return null;
        }

        // JSON first (recommended)
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        // Legacy serialized payloads: allow specified classes (e.g. stdClass)
        $allowed = empty($allowedClasses) ? [\stdClass::class] : $allowedClasses;
        $value = @unserialize($raw, ['allowed_classes' => $allowed]);

        // distinguish unserialize failure from valid serialized false ("b:0;")
        if ($value === false && $raw !== 'b:0;') {
            return null;
        }

        return $value;
    }
    // ─────────────────────────────────────────────────
    //  اتصال Redis
    // ─────────────────────────────────────────────────

    private function tryConnectRedis(): void
    {
        if (!extension_loaded('redis')) {
            return;
        }

        $config = config('redis');
        $enabled = $config['enabled'] ?? true;
        if (!$enabled || in_array(strtolower((string)$enabled), ['false', '0', 'no', 'off'], true)) {
            return;
        }

        $host     = $config['host'] ?? '127.0.0.1';
        $port     = (int)($config['port'] ?? 6379);
        $password = $config['password'] ?? '';
        $db       = (int)($config['db'] ?? 0);
        $timeout  = (float)($config['timeout'] ?? 1.5);
        $this->redisPrefix = ($config['prefix'] ?? 'chortke') . ':';

        try {
            $r = new \Redis();
            if (!$r->connect($host, $port, $timeout)) {
                return;
            }

            if ($password !== '') {
                $r->auth($password);
            }

            $r->select($db);
            $r->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $r->ping(); // تست واقعی اتصال

            $this->redis  = $r;
            $this->driver = 'redis';
        } catch (\Throwable) {
            $this->redis  = null;
            $this->driver = 'file';
        }
    }

    // ─────────────────────────────────────────────────
    //  عملیات اصلی
    // ─────────────────────────────────────────────────

    public function put(string $key, mixed $value, int $minutes = 60): bool
    {
        // CORE-040: JSON encoding preferentially to mitigate deserialization risk
        $payload = is_object($value) ? serialize($value) : json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($this->driver === 'redis') {
            return (bool) $this->redis->setEx(
                $this->redisKey($key),
                $minutes * 60,
                $payload
            );
        }

        return $this->filePut($key, $value, $minutes);
    }

    /**
     * Alias برای put (برای سازگاری)
     */
    public function set(string $key, mixed $value, int $minutes = 60): bool
    {
        return $this->put($key, $value, $minutes);
    }

    /**
     * ذخیره با TTL صریح بر حسب دقیقه.
     * این متد برای جلوگیری از ابهام API قدیمی set/put اضافه شده است.
     */
    public function setMinutes(string $key, mixed $value, int $minutes = 60): bool
    {
        return $this->put($key, $value, $minutes);
    }

    public function putMinutes(string $key, mixed $value, int $minutes = 60): bool
    {
        return $this->put($key, $value, $minutes);
    }

    /**
     * ذخیره با TTL صریح بر حسب ثانیه.
     * Core\Cache همچنان درونی بر اساس دقیقه کار می‌کند؛ این wrapper تبدیل امن انجام می‌دهد.
     */
    public function setSeconds(string $key, mixed $value, int $seconds = 3600): bool
    {
        if ($seconds <= 0) {
            return $this->forget($key);
        }

        return $this->put($key, $value, max(1, (int) ceil($seconds / 60)));
    }

    public function putSeconds(string $key, mixed $value, int $seconds = 3600): bool
    {
        return $this->setSeconds($key, $value, $seconds);
    }

    public function rememberSeconds(string $key, int $seconds, callable $callback): mixed
    {
        if ($seconds <= 0) {
            return $callback();
        }

        return $this->remember($key, max(1, (int) ceil($seconds / 60)), $callback);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->driver === 'redis') {
            $raw = $this->redis->get($this->redisKey($key));
            if ($raw === false) {
                return $default;
            }
            return $this->safeUnserialize($raw);
        }

        return $this->fileGet($key, $default);
    }

    /**
     * خواندن مقدار کش و حذف فوری آن (Get and Forget)
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function has(string $key): bool
    {
        if ($this->driver === 'redis') {
            return (bool) $this->redis->exists($this->redisKey($key));
        }

        return $this->fileHas($key);
    }

    public function forget(string $key): bool
    {
        if ($this->driver === 'redis') {
            return (bool) $this->redis->del($this->redisKey($key));
        }

        return $this->fileForget($key);
    }

    public function flush(): bool
    {
        if ($this->driver === 'redis') {
            $iterator = null;
            $pattern = $this->redisPrefix . '*';

            while (false !== ($batch = $this->redis->scan($iterator, $pattern, 100))) {
                if (!empty($batch)) {
                    $this->redis->del($batch);
                }
                if ($iterator === 0 || $iterator === '0') {
                    break;
                }
            }

            return true;
        }

        return $this->fileFlush();
    }

    public function remember(string $key, int $minutes, callable $callback): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        // CORE-039: Cache Stampede mitigation using distributed double-check lock
        $lockKey = 'remember:' . $key;
        $lockAcquired = false;
        try {
            $lockAcquired = $this->lock($lockKey, 30, 5);
        } catch (\RuntimeException $e) {
            // Lock is fail-closed in production without Redis. Proceed without locking for non-atomic operations.
            $lockAcquired = false;
        }

        if ($lockAcquired) {
            try {
                // Double check
                $value = $this->get($key);
                if ($value !== null) {
                    return $value;
                }

                $value = $callback();
                $this->put($key, $value, $minutes);
                return $value;
            } finally {
                $this->unlock($lockKey);
            }
        }

        // Fallback if lock could not be acquired: introduce random backoff sleep and secondary read attempt
        usleep(random_int(50000, 150000)); // 50ms to 150ms sleep
        $secondaryValue = $this->get($key);
        if ($secondaryValue !== null) {
            return $secondaryValue;
        }

        return $callback();
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->forever($key, $value);
        return $value;
    }

    public function forever(string $key, mixed $value): bool
    {
        $payload = is_object($value) ? serialize($value) : json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($this->driver === 'redis') {
            // یک سال به ثانیه: ۳۱۵۳۶۰۰۰ (همسان با رانر فایل برای جلوگیری از انباشت بی‌نهایت حافظه)
            $oneYear = 31536000;
            return (bool) $this->redis->setex(
                $this->redisKey($key),
                $oneYear,
                $payload
            );
        }

        return $this->filePut($key, $value, 525_600); // ~1 سال
    }

    // ─────────────────────────────────────────────────
    //  Counter (atomic در Redis)
    // ─────────────────────────────────────────────────

    public function increment(string $key, int $step = 1, int $ttlSeconds = 0): int|false
    {
        // CORE-038: Fail-closed if distributed state operations called without real-time central store
        if ($this->driver !== 'redis' && (config('app.env') === 'production' || env('APP_ENV') === 'production')) {
            throw new \RuntimeException('Atomic counters/limiters require Redis driver in production to prevent split-brain.', 500);
        }

        if ($this->driver === 'redis') {
            // اسکریپت لوآ برای اتمیک اینکریمنت + تنظیم انقضا در صورتی که از قبل ندارد
            $script = <<<'LUA'
local current = redis.call('INCRBY', KEYS[1], ARGV[1])
local ttl = redis.call('TTL', KEYS[1])
local targetTtl = tonumber(ARGV[2])
-- فقط اگر کلید تازه ساخته شده یا انقضا ندارد (TTL = -1) و درخواست TTL داده شده باشد
if ttl == -1 and targetTtl > 0 then
    redis.call('EXPIRE', KEYS[1], targetTtl)
end
return current
LUA;
            $result = $this->redis->eval($script, [$this->redisKey($key), $step, $ttlSeconds], 1);
            return $result !== false ? (int)$result : false;
        }

        // M20 Fix: افزایش اتمیک فایل با flock برای مسدود کردن تداخلات همزمانی
        $file = $this->cacheFile($key);
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fh = fopen($file, 'c+');
        if (!$fh) {
            return false;
        }

        $new = false;
        if (flock($fh, LOCK_EX)) {
            $size = @filesize($file);
            $raw = ($size > 0) ? fread($fh, $size) : '';

            $currentValue = 0;
            // یک سال پیش‌فرض (بر حسب ثانیه)
            $expireAt = time() + ($ttlSeconds > 0 ? $ttlSeconds : (525_600 * 60));

            if (!empty($raw)) {
                $data = $this->safeUnserialize($raw);
                if (is_array($data) && isset($data['expire_at'])) {
                    // اگر کلید منقضی شده باشد مقدار قبلی نادیده گرفته می‌شود
                    if ($data['expire_at'] >= time()) {
                        $currentValue = (int)($data['value'] ?? 0);
                        // اگر انقضای جدید تعیین نشده، انقضای قبلی را حفظ کن
                        if ($ttlSeconds <= 0) {
                            $expireAt = $data['expire_at'];
                        }
                    }
                }
            }

            $new = $currentValue + $step;

            $newData = serialize([
                'expire_at' => $expireAt,
                'value'     => $new,
            ]);

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $newData);
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        return $new;
    }

    public function decrement(string $key, int $step = 1, int $ttlSeconds = 0): int|false
    {
        return $this->increment($key, -$step, $ttlSeconds);
    }

    public function incrementFloat(string $key, float $step = 1.0, int $ttlSeconds = 0): float|false
    {
        if ($this->driver !== 'redis' && (config('app.env') === 'production' || env('APP_ENV') === 'production')) {
            throw new \RuntimeException('Atomic float counters require Redis driver in production.', 500);
        }

        if ($this->driver === 'redis') {
            $script = <<<'LUA'
local current = redis.call('INCRBYFLOAT', KEYS[1], ARGV[1])
local ttl = redis.call('TTL', KEYS[1])
local targetTtl = tonumber(ARGV[2])
if ttl == -1 and targetTtl > 0 then
    redis.call('EXPIRE', KEYS[1], targetTtl)
end
return current
LUA;
            $result = $this->redis->eval($script, [$this->redisKey($key), $step, $ttlSeconds], 1);
            return $result !== false ? (float)$result : false;
        }

        // M20 Fix: افزایش اعشاری اتمیک فایل با flock
        $file = $this->cacheFile($key);
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fh = fopen($file, 'c+');
        if (!$fh) {
            return false;
        }

        $new = false;
        if (flock($fh, LOCK_EX)) {
            $size = @filesize($file);
            $raw = ($size > 0) ? fread($fh, $size) : '';

            $currentValue = 0.0;
            $expireAt = time() + ($ttlSeconds > 0 ? $ttlSeconds : (525_600 * 60));

            if (!empty($raw)) {
                $data = $this->safeUnserialize($raw);
                if (is_array($data) && isset($data['expire_at'])) {
                    if ($data['expire_at'] >= time()) {
                        $currentValue = (float)($data['value'] ?? 0.0);
                        if ($ttlSeconds <= 0) {
                            $expireAt = $data['expire_at'];
                        }
                    }
                }
            }

            $new = $currentValue + $step;

            $newData = serialize([
                'expire_at' => $expireAt,
                'value'     => $new,
            ]);

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $newData);
            fflush($fh);
            flock($fh, LOCK_UN);
        }
        fclose($fh);

        return $new;
    }

    // ─────────────────────────────────────────────────
    //  TTL باقی‌مانده (ثانیه)
    // ─────────────────────────────────────────────────

    public function ttl(string $key): int
    {
        if ($this->driver === 'redis') {
            return (int) $this->redis->ttl($this->redisKey($key));
        }

        $file = $this->cacheFile($key);
        if (!file_exists($file)) {
            return -2;
        }
        $raw = file_get_contents($file);
$data = $this->safeUnserialize($raw === false ? null : $raw);
        if ($data === false) {
            return -2;
        }
        $left = $data['expire_at'] - time();
        return max(-1, $left);
    }

    // ─────────────────────────────────────────────────
    //  Tagged Cache
    // ─────────────────────────────────────────────────

    /**
     * کش با تگ — امکان flush گروهی
     *
     * مثال:
     *   cache()->tags(['users'])->put('user:1', $data, 10);
     *   cache()->tags(['users'])->flush();
     */
    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    // ─────────────────────────────────────────────────
    //  Redis Raw (برای RateLimiter و سایر موارد خاص)
    // ─────────────────────────────────────────────────

    public function redis(): ?\Redis
    {
        return $this->redis;
    }

    // ─────────────────────────────────────────────────
    //  Distributed Locking (برای عملیات concurrent)
    // ─────────────────────────────────────────────────

    /**
     * Acquire a distributed lock
     *
     * Redis: استفاده از SET NX EX برای atomic locking
     * File: استفاده از file locking با timeout
     *
     * @param string $key نام lock
     * @param int $ttl ثانیه‌های timeout (فقط Redis)
     * @return bool آیا lock گرفته شد؟
     */
    /**
     * Acquire a distributed lock
     *
     * Redis: استفاده از SET NX EX برای atomic locking
     * File: استفاده از file locking با timeout
     *
     * @param string $key نام lock
     * @param int $ttl ثانیه‌های timeout (فقط Redis)
     * @param int $wait حداکثر ثانیه‌های انتظار برای آزادسازی قفل (فایل)
     * @return bool آیا lock گرفته شد؟
     */
    public function lock(string $key, int $ttl = 30, int $wait = 1): bool
    {
        // CORE-038: Prevent unsafe local lock fallback on production
        if ($this->driver !== 'redis' && (config('app.env') === 'production' || env('APP_ENV') === 'production')) {
            throw new \RuntimeException('Distributed locking functionality requires the Redis driver in production.', 500);
        }

        $lockKey = 'lock:' . $key;

        if ($this->driver === 'redis') {
            // M19 Fix: ذخیره سازی شناسه مالکیت قفل در سشن پردازش جاری
            $uniqueId = uniqid('', true);
            $result = $this->redis->set(
                $this->redisKey($lockKey),
                $uniqueId,
                ['nx', 'ex' => $ttl]
            );

            if ($result !== false) {
                $this->redisLocks[$lockKey] = $uniqueId;
                return true;
            }
            return false;
        }

        // M18 Fix: پیاده‌سازی قفل‌گذاری فایل با قابلیت تعیین زمان داینامیک انتظار ($wait)
        $lockFile = $this->cacheDir . 'locks/' . md5($lockKey) . '.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        $fh = fopen($lockFile, 'c');
        if (!$fh) {
            return false;
        }

        // Try to acquire lock with timeout (simulate)
        $start = microtime(true);
        while (!flock($fh, LOCK_EX | LOCK_NB)) {
            if ((microtime(true) - $start) > $wait) { // استفاده از پارامتر پویا
                fclose($fh);
                return false;
            }
            usleep(1000); // 1ms
        }

        // Store file handle for unlock
        $this->fileLocks[$lockKey] = $fh;
        return true;
    }

    /**
     * Release a distributed lock
     *
     * @param string $key نام lock
     * @return bool آیا unlock موفق بود؟
     */
    public function unlock(string $key): bool
    {
        $lockKey = 'lock:' . $key;

        if ($this->driver === 'redis') {
            // M19 Ultimate Fix: آزادسازی کاملاً امن و اتمیک قفل صرفاً در صورت تطابق توکن مالکیت (Lua)
            $owner = $this->redisLocks[$lockKey] ?? null;
            if ($owner === null) {
                return false; // این پردازش مالک قفل نبوده و اجازه آزاد سازی ندارد
            }

            unset($this->redisLocks[$lockKey]);

            $script = '
                if redis.call("get", KEYS[1]) == ARGV[1] then
                    return redis.call("del", KEYS[1])
                else
                    return 0
                end
            ';

            return (bool) $this->redis->eval($script, [$this->redisKey($lockKey), $owner], 1);
        }

        // File-based unlock
        if (!isset($this->fileLocks[$lockKey])) {
            return false;
        }

        $fh = $this->fileLocks[$lockKey];
        flock($fh, LOCK_UN);
        fclose($fh);
        unset($this->fileLocks[$lockKey]);

        return true;
    }

    /**
     * Execute callback with automatic lock/unlock
     *
     * مثال:
     *   $result = cache()->withLock('user:123:update', function() {
     *       // عملیات atomic
     *       return doSomething();
     *   });
     *
     * @param string $key نام lock
     * @param callable $callback عملیات مورد نظر
     * @param int $ttl ثانیه‌های timeout
     * @return mixed نتیجه callback یا false اگر lock شکست خورد
     */
    public function withLock(string $key, callable $callback, int $ttl = 30): mixed
    {
        if (!$this->lock($key, $ttl)) {
            return false; // Could not acquire lock
        }

        try {
            return $callback();
        } finally {
            $this->unlock($key);
        }
    }

    /**
     * پاکسازی تمام قفل‌های ثبت‌شده در مموری (کاربرد در فرآیندهای طولانی مثل Queue Workers)
     */
    public function flushAllLocks(): void
    {
        // 1. Close file handles
        foreach ($this->fileLocks as $key => $fh) {
            if (is_resource($fh)) {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
        $this->fileLocks = [];

        // 2. Clear redis locks array
        $this->redisLocks = [];
    }

    // ─────────────────────────────────────────────────
    //  Cleanup — فقط در حالت فایل
    // ─────────────────────────────────────────────────

    public function cleanup(): int
    {
        $logger = null;
        if (function_exists('logger')) {
            try {
                $logger = logger();
            } catch (\Throwable) {
                $logger = null;
            }
        }

        if ($this->driver === 'redis') {
            if ($logger) {
                $logger->info('Cache cleanup skipped — Redis manages TTL automatically', []);
            }
            return 0;
        }

        $files   = glob($this->cacheDir . '*.cache') ?: [];
        $cleaned = 0;

        foreach ($files as $file) {
            $raw = file_get_contents($file);
            $data = $this->safeUnserialize($raw === false ? null : $raw);
            if ($data === false || $data['expire_at'] < time()) {
                @unlink($file);
                $cleaned++;
            }
        }

        if ($logger) {
            $logger->info('cache.file.cleanup.completed', [
                'channel' => 'cache',
                'cleaned' => $cleaned,
            ]);
        }

        return $cleaned;
    }

    // ─────────────────────────────────────────────────
    //  پشتیبان فایل
    // ─────────────────────────────────────────────────

    private function filePut(string $key, mixed $value, int $minutes): bool
    {
        $data = [
            'expire_at' => time() + ($minutes * 60),
            'value'     => $value,
        ];

        // CORE-040: Write as JSON to avoid dangerous deserialization payload risk.
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Fallback safe serialization only for objects
            $encoded = serialize($data);
        }

        return (bool) file_put_contents($this->cacheFile($key), $encoded);
    }

    private function fileGet(string $key, mixed $default): mixed
    {
        $file = $this->cacheFile($key);
        if (!file_exists($file)) {
            return $default;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return $default;
        }

        // CORE-040: Try json_decode first to enforce secure formats
        $data = json_decode($raw, true);
        if ($data === null) {
            $data = $this->safeUnserialize($raw);
        }

        if ($data === false || $data === null || $data['expire_at'] < time()) {
            @unlink($file);
            return $default;
        }

        return $data['value'];
    }

    private function fileHas(string $key): bool
    {
        $file = $this->cacheFile($key);
        if (!file_exists($file)) {
            return false;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return false;
        }

        $data = json_decode($raw, true);
        if ($data === null) {
            $data = $this->safeUnserialize($raw);
        }

        if ($data === false || $data === null || $data['expire_at'] < time()) {
            @unlink($file);
            return false;
        }
        return true;
    }

    private function fileForget(string $key): bool
    {
        $file = $this->cacheFile($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }

    private function fileFlush(): bool
    {
        foreach (glob($this->cacheDir . '*.cache') ?: [] as $file) {
            @unlink($file);
        }
        return true;
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDir . md5($key) . '.cache';
    }

    public function redisKey(string $key): string
    {
        return $this->redisPrefix . $key;
    }

    private function __clone() {}

    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize singleton Cache');
    }
}



// ═══════════════════════════════════════════════════════════════
//  Tagged Cache
// ═══════════════════════════════════════════════════════════════

/**
 * کش تگ‌دار — امکان flush گروهی کلیدها
 *
 * Redis: از Sets استفاده می‌کند (سریع و atomic)
 * File:  کلیدهای هر تگ را در یک فایل JSON ذخیره می‌کند
 */
class TaggedCache
{
    public function __construct(
        private Cache $cache,
        private array $tags
    ) {}

    public function put(string $key, mixed $value, int $minutes = 60): bool
    {
        $taggedKey = $this->taggedKey($key);
        $this->registerKey($taggedKey);
        return $this->cache->put($taggedKey, $value, $minutes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->taggedKey($key), $default);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->taggedKey($key));
    }

    public function forget(string $key): bool
    {
        $taggedKey = $this->taggedKey($key);
        $this->unregisterKey($taggedKey);
        return $this->cache->forget($taggedKey);
    }

    public function remember(string $key, int $minutes, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->put($key, $value, $minutes);
        return $value;
    }

    /** حذف تمام کلیدهای این تگ */
    public function flush(): void
    {
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            foreach ($this->tags as $tag) {
                $setKey  = $this->cache->redisKey('tag:' . $tag);
                $members = $redis->sMembers($setKey);
                if (!empty($members)) {
                    $redis->del($members);
                }
                $redis->del($setKey);
            }
            return;
        }

        // File mode
        foreach ($this->tags as $tag) {
            $indexFile = $this->tagIndexFile($tag);
            if (!file_exists($indexFile)) {
                continue;
            }
            $keys = json_decode(file_get_contents($indexFile), true) ?? [];
            foreach ($keys as $k) {
                $this->cache->forget($k);
            }
            @unlink($indexFile);
        }
    }

    // ─────────────────────────────────────────────────

    private function taggedKey(string $key): string
    {
        return implode('|', $this->tags) . ':' . $key;
    }

    private function registerKey(string $taggedKey): void
    {
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            $fullTaggedKey = $this->cache->redisKey($taggedKey);
            foreach ($this->tags as $tag) {
                $redis->sAdd($this->cache->redisKey('tag:' . $tag), $fullTaggedKey);
            }
            return;
        }

        // FIX C-8: TaggedCache در حالت File بدون قفل بود.
        // دو درخواست همزمان هر دو فایل را می‌خواندند، یکی را می‌نوشتند
        // و کلید دیگری گم می‌شد. فلاک مانع این race condition می‌شود.
        foreach ($this->tags as $tag) {
            $indexFile = $this->tagIndexFile($tag);
            $fh = fopen($indexFile, 'c+');
            if (!$fh) {
                continue;
            }
            if (flock($fh, LOCK_EX)) {
                $content  = stream_get_contents($fh);
                $existing = $content ? (json_decode($content, true) ?? []) : [];
                if (!in_array($taggedKey, $existing, true)) {
                    $existing[] = $taggedKey;
                    ftruncate($fh, 0);
                    rewind($fh);
                    fwrite($fh, json_encode($existing));
                }
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }
    }

    private function unregisterKey(string $taggedKey): void
    {
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            $fullTaggedKey = $this->cache->redisKey($taggedKey);
            foreach ($this->tags as $tag) {
                $redis->sRem($this->cache->redisKey('tag:' . $tag), $fullTaggedKey);
            }
            return;
        }

        foreach ($this->tags as $tag) {
            $indexFile = $this->tagIndexFile($tag);
            if (!file_exists($indexFile)) {
                continue;
            }
            
            $fh = fopen($indexFile, 'c+');
            if (!$fh) {
                continue;
            }
            if (flock($fh, LOCK_EX)) {
                $content  = stream_get_contents($fh);
                $existing = $content ? (json_decode($content, true) ?? []) : [];
                $filtered = array_values(array_filter($existing, fn($k) => $k !== $taggedKey));
                
                if (count($filtered) !== count($existing)) {
                    ftruncate($fh, 0);
                    rewind($fh);
                    fwrite($fh, json_encode($filtered));
                }
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }
    }

    private function tagIndexFile(string $tag): string
    {
        $dir = __DIR__ . '/../storage/cache/tags/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . md5($tag) . '.json';
    }

    // M17 Fix: متد منقضی شده و بلااستفاده به نفع متد تجمیع شده و پابلیک کلاس والد حذف شد
}
