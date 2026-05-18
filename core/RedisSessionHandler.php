<?php

declare(strict_types=1);
namespace Core;

/**
 * Redis Session Handler
 * 
 * مدیریت Session با Redis + Fallback به File
 * خودکار بین Redis و فایل سوئیچ می‌کند
 */
class RedisSessionHandler implements \SessionHandlerInterface
{
    private ?\Redis $redis = null;
    private bool $useRedis = false;
    private static bool $hasFailed = false; // H13 Fix: جلوگیری از تلاش مجدد در طول کل حیات این پروسس (مخصوصاً در CLI)
    private string $prefix = 'chortke:session:';
    private int $ttl = 7200; // 2 hours default
    private string $savePath = '';

    public function __construct()
    {
        $this->tryConnectRedis();
        $this->ttl = (int) config('session.lifetime', 7200);
    }

    private function tryConnectRedis(): void
    {
        // H13 Fix: اگر قبلاً فیل شده، مستقیماً برو روی فایل
        if (self::$hasFailed) {
            $this->useRedis = false;
            $this->savePath = __DIR__ . '/../storage/sessions';
            return;
        }

        // Use explicit session driver configuration for Redis-first session storage.
        $sessionDriver = config('session.driver', 'redis');
        if ($sessionDriver !== 'redis') {
            $this->useRedis = false;
            $this->savePath = __DIR__ . '/../storage/sessions';
            if (function_exists('logger')) {
                try {
                    logger()->info('Session handler: Redis disabled by session.driver config, using file fallback.', ['driver' => $sessionDriver]);
                } catch (\Throwable $e) {
                    // ignore logger errors
                }
            }
            return;
        }

        // استفاده از تنظیمات مشترک Cache
        $cache = \Core\Cache::getInstance();

        if ($cache->driver() === 'redis') {
            try {
                $this->redis = $cache->redis();
                if (!$this->redis) {
                    throw new \RuntimeException('Redis connection could not be established.');
                }
                $this->useRedis = true;

                if (function_exists('logger')) {
                    try {
                        logger()->info('Session handler: Redis connected via Cache', []);
                    } catch (\Throwable $e) {
                        // ignore logger errors
                    }
                }
            } catch (\Throwable $e) {
                self::$hasFailed = true;
                $this->useRedis = false;
                if (function_exists('logger')) {
                    try {
                        logger()->critical('Session handler: Redis connection failed on production. Falling back to file-based sessions to prevent complete system outage.', ['error' => $e->getMessage()]);
                    } catch (\Throwable $ignore) {}
                }
                $this->savePath = __DIR__ . '/../storage/sessions';
            }
        } else {
            $this->redis = null;
            $this->useRedis = false;

            if (function_exists('logger')) {
                try {
                    logger()->info('Session handler: Fallback to file', []);
                } catch (\Throwable $e) {
                    // ignore logger errors
                }
            }
        }
    }

    public function open(string $path, string $name): bool
    {
        if (!$this->useRedis) {
            $this->savePath = $path ?: __DIR__ . '/../storage/sessions';
            if (!is_dir($this->savePath)) {
                mkdir($this->savePath, 0755, true);
            }
        }
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        if ($this->useRedis) {
            try {
                $data = $this->redis->get($this->prefix . $id);
                return $data === false ? '' : $data;
            } catch (\Throwable $e) {
                $this->fallbackToFile($e);
                return $this->fileRead($id);
            }
        }

        return $this->fileRead($id);
    }

    public function write(string $id, string $data): bool
    {
        if ($this->useRedis) {
            try {
                return (bool) $this->redis->setEx(
                    $this->prefix . $id,
                    $this->ttl,
                    $data
                );
            } catch (\Throwable $e) {
                $this->fallbackToFile($e);
                return $this->fileWrite($id, $data);
            }
        }

        return $this->fileWrite($id, $data);
    }

    public function destroy(string $id): bool
    {
        if ($this->useRedis) {
            try {
                return (bool) $this->redis->del($this->prefix . $id);
            } catch (\Throwable $e) {
                $this->fallbackToFile($e);
                return $this->fileDestroy($id);
            }
        }

        return $this->fileDestroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        if ($this->useRedis) {
            // Redis automatically handles TTL
            return 0;
        }

        return $this->fileGc($max_lifetime);
    }

    // ─────────────────────────────────────────────────
    //  File Fallback Methods
    // ─────────────────────────────────────────────────

    private function fileRead(string $id): string|false
    {
        $file = $this->getFilePath($id);
        if (!file_exists($file)) {
            return '';
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return '';
        }

        return $data;
    }

    private function fileWrite(string $id, string $data): bool
    {
        $file = $this->getFilePath($id);
        return file_put_contents($file, $data, LOCK_EX) !== false;
    }

    private function fileDestroy(string $id): bool
    {
        $file = $this->getFilePath($id);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    private function fileGc(int $max_lifetime): int|false
    {
        $files = glob($this->savePath . '/sess_*') ?: [];
        $now = time();
        $deleted = 0;

        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file) > $max_lifetime)) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function getFilePath(string $id): string
    {
        return $this->savePath . '/sess_' . $id;
    }

    private function fallbackToFile(\Throwable $e): void
    {
        self::$hasFailed = true; // ثبت وضعیت خرابی سیستمی برای بقیه درخواست یا لوپ
        
        if ($this->useRedis) {
            $this->useRedis = false;
            $this->redis = null;

            if (function_exists('logger')) {
                try {
                    logger()->critical('Redis session store connection was lost. Downgrading to file session handler in production to maintain availability.', [
                        'channel' => 'session',
                        'error' => $e->getMessage()
                    ]);
                } catch (\Throwable $ignore) {}
            }

            // Initialize file path securely with strict permissions in production
            $this->savePath = __DIR__ . '/../storage/sessions';
            if (!is_dir($this->savePath)) {
                mkdir($this->savePath, 0700, true);
            }
        }
    }

    /**
     * Get current driver
     */
    public function driver(): string
    {
        return $this->useRedis ? 'redis' : 'file';
    }
}
