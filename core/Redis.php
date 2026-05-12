<?php

declare(strict_types=1);

namespace Core;

/**
 * Redis connection wrapper for application services.
 *
 * This wrapper uses the PHP Redis extension and exposes Redis commands via __call().
 */
class Redis
{
    private ?\Redis $client = null;
    private bool $connected = false;

    public function __construct()
    {
        if (!extension_loaded('redis')) {
            $this->connected = false;
            return;
        }

        $config = config('redis');
        $enabled = $config['enabled'] ?? true;
        if (!$enabled || in_array(strtolower((string)$enabled), ['false', '0', 'no', 'off'], true)) {
            $this->connected = false;
            return;
        }

        $host     = $config['host'] ?? '127.0.0.1';
        $port     = (int)($config['port'] ?? 6379);
        $timeout  = (float)($config['timeout'] ?? 1.5);
        $password = $config['password'] ?? '';
        $db       = (int)($config['db'] ?? 0);

        try {
            $redis = new \Redis();
            if (!$redis->connect($host, $port, $timeout)) {
                return;
            }

            if ($password !== '') {
                $redis->auth($password);
            }

            $redis->select($db);
            $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
            $redis->ping();

            $this->client = $redis;
            $this->connected = true;
        } catch (\Throwable) {
            $this->client = null;
            $this->connected = false;
        }
    }

    public function isAvailable(): bool
    {
        return $this->connected && $this->client !== null;
    }

    public function getClient(): ?\Redis
    {
        return $this->client;
    }

    /**
     * Get keys matching pattern using SCAN (non-blocking alternative to keys())
     * 
     * ✅ Performance: O(N) with server-side iteration (doesn't block Redis)
     * ❌ keys(): O(N) but blocks Redis server completely
     * 
     * @param string $pattern Key pattern to match (e.g., "user:*")
     * @param int $count Hint about number of keys to return per iteration
     * @return array<string> All matching keys
     */
    public function scanKeys(string $pattern, int $count = 100): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $keys = [];
        $iterator = null;

        // scan expects &$iterator, performs iterations and updates the pointer
        while (false !== ($batch = $this->client->scan($iterator, $pattern, $count))) {
            foreach ($batch as $key) {
                $keys[] = $key;
            }
            if ($iterator === 0 || $iterator === '0') {
                break;
            }
        }

        return $keys;
    }

    public function __call(string $name, array $arguments)
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Redis is not available in the current environment.');
        }

        return $this->client->{$name}(...$arguments);
    }
}
