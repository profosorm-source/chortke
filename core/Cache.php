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

    /** نوع درایور فعال: 'redis' یا 'file' */
    public function driver(): string
    {
        return $this->driver;
    }

private function safeUnserialize($raw)
{
    if ($raw === null || $raw === false) {
        return null;
    }

    // JSON first (recommended)
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $json;
    }

    // fallback legacy serialize
    return @unserialize($raw, ['allowed_classes' => false]);
}
    // ─────────────────────────────────────────────────
    //  اتصال Redis
    // ─────────────────────────────────────────────────

    private function tryConnectRedis(): void
    {
        if (!extension_loaded('redis')) {
            return;
        }

        $enabled = env('REDIS_ENABLED', 'true');
        if (in_array(strtolower((string)$enabled), ['false', '0', 'no', 'off'], true)) {
            return;
        }

        $host     = env('REDIS_HOST', '127.0.0.1');
        $port     = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD', '');
        $db       = (int) env('REDIS_DB', 0);
        $timeout  = (float) env('REDIS_TIMEOUT', 1.5);
        $this->redisPrefix = env('REDIS_PREFIX', 'chortke') . ':';

        try {
            $r = new \Redis();
            if (!$r->connect($host, $port, $timeout)) {
                return;
            }

            if ($password !== '') {
                $r->auth($password);
            }

            $r->select($db);
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
        if ($this->driver === 'redis') {
            return (bool) $this->redis->setEx(
                $this->redisKey($key),
                $minutes * 60,
                serialize($value)
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
            // فقط کلیدهای این پروژه را پاک می‌کند (نه همه Redis)
            $keys = [];
            $cursor = '0';
            do {
                $result = $this->redis->scan($cursor, 'MATCH', $this->redisPrefix . '*', 'COUNT', 100);
                $cursor = $result[0];
                $keys = array_merge($keys, $result[1]);
            } while ($cursor !== '0');

            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            return true;
        }

        return $this->fileFlush();
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
        if ($this->driver === 'redis') {
            return (bool) $this->redis->set(
                $this->redisKey($key),
                serialize($value)
            );
        }

        return $this->filePut($key, $value, 525_600); // ~1 سال
    }

    // ─────────────────────────────────────────────────
    //  Counter (atomic در Redis)
    // ─────────────────────────────────────────────────

    public function increment(string $key, int $step = 1): int|false
    {
        if ($this->driver === 'redis') {
            return $step === 1
                ? $this->redis->incr($this->redisKey($key))
                : $this->redis->incrBy($this->redisKey($key), $step);
        }

        $current = (int) $this->get($key, 0);
        $new     = $current + $step;
        $this->forever($key, $new);
        return $new;
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->increment($key, -$step);
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
    public function lock(string $key, int $ttl = 30): bool
    {
        $lockKey = 'lock:' . $key;

        if ($this->driver === 'redis') {
            // SET lock:key value unique_id NX EX ttl
            $uniqueId = uniqid('', true);
            $result = $this->redis->set(
                $this->redisKey($lockKey),
                $uniqueId,
                ['nx', 'ex' => $ttl]
            );
            return $result !== false;
        }

        // File-based locking with timeout simulation
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
            if ((microtime(true) - $start) > 1) { // 1 second timeout for file
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
            return (bool) $this->redis->del($this->redisKey($lockKey));
        }

        // File-based unlock
        if (!isset($this->fileLocks[$lockKey])) {
            return false;
        }

        $fh = $this->fileLocks[$lockKey];
        flock($fh, LOCK_UN);
        fclose($fh);
        unset($this->fileLocks[$lockKey]);

        // Clean up lock file
        $lockFile = $this->cacheDir . 'locks/' . md5($lockKey) . '.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }

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

    // Storage for file-based locks
    private array $fileLocks = [];

    // ─────────────────────────────────────────────────
    //  Cleanup — فقط در حالت فایل
    // ─────────────────────────────────────────────────

    public function cleanup(): int
    {
        if ($this->driver === 'redis') {
            $this->logger->info('Cache cleanup skipped — Redis manages TTL automatically', []);
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

        $this->logger->info('cache.file.cleanup.completed', [
    'channel' => 'cache',
    'cleaned' => $cleaned,
]);
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
        return (bool) file_put_contents($this->cacheFile($key), serialize($data));
    }

    private function fileGet(string $key, mixed $default): mixed
    {
        $file = $this->cacheFile($key);
        if (!file_exists($file)) {
            return $default;
        }

        $raw = file_get_contents($file);
$data = $this->safeUnserialize($raw === false ? null : $raw);
        if ($data === false || $data['expire_at'] < time()) {
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
$data = $this->safeUnserialize($raw === false ? null : $raw);
        if ($data === false || $data['expire_at'] < time()) {
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

    private function redisKey(string $key): string
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
                $setKey  = 'tag:' . $tag;
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
            foreach ($this->tags as $tag) {
                $redis->sAdd('tag:' . $tag, $taggedKey);
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
            foreach ($this->tags as $tag) {
                $redis->sRem('tag:' . $tag, $taggedKey);
            }
            return;
        }

        foreach ($this->tags as $tag) {
            $indexFile = $this->tagIndexFile($tag);
            if (!file_exists($indexFile)) {
                continue;
            }
            $existing = json_decode(file_get_contents($indexFile), true) ?? [];
            $existing = array_values(array_filter($existing, fn($k) => $k !== $taggedKey));
            file_put_contents($indexFile, json_encode($existing));
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
	
	private function safeUnserialize($raw)
{
    if ($raw === null || $raw === false) {
        return null;
    }

    // JSON first (recommended)
    $json = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return $json;
    }

    // Legacy serialized payloads:
    // allow only stdClass (used by PDO fetch objects in dashboard cache)
    $value = @unserialize($raw, ['allowed_classes' => [\stdClass::class]]);

    // distinguish unserialize failure from valid serialized false ("b:0;")
    if ($value === false && $raw !== 'b:0;') {
        return null;
    }

    return $value;
}
}
