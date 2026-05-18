<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

class CircuitBreaker
{
    private Cache $cache;
    private int $failureThreshold;
    private int $retryTimeoutSeconds;

    public function __construct(Cache $cache)
    {
        $config = config('circuit_breaker', []);
        $this->cache = $cache;
        $this->failureThreshold = (int)($config['failure_threshold'] ?? 5);
        $this->retryTimeoutSeconds = (int)($config['retry_timeout_seconds'] ?? 60);
    }

    public function call(string $name, callable $operation)
    {
        $lockKey = "cb_state_{$name}";

        // CORE-052: Wrap state fetching & window-transition in an atomic lock to prevent concurrent race conditions
        $state = $this->cache->withLock($lockKey, function() use ($name) {
            $st = $this->getState($name);
            if ($st['status'] === 'open') {
                if ($this->isRetryWindowExpired($st)) {
                    $this->setState($name, 'half_open', 0);
                    return $this->getState($name);
                }
            }
            return $st;
        }, 5);

        if ($state['status'] === 'open') {
            throw new RuntimeException("Circuit breaker '{$name}' is open");
        }

        $probeLockKey = "cb_half_open_probe_{$name}";
        $hasProbeLock = false;

        if ($state['status'] === 'half_open') {
            // Attempt to acquire probe lock for 15 seconds (sufficient time to test downstream service)
            if (!$this->cache->lock($probeLockKey, 15)) {
                // If another thread is already probing, fail fast to prevent stampeding
                throw new RuntimeException("Circuit breaker '{$name}' is in half-open state. A probe request is already in progress.");
            }
            $hasProbeLock = true;
        }

        try {
            $result = $operation();
            
            // Fast atomic success reset
            $this->cache->withLock($lockKey, function() use ($name) {
                $this->setState($name, 'closed', 0);
            }, 5);
            
            return $result;
        } catch (\Throwable $exception) {
            // Fast atomic failure counter increment
            $this->cache->withLock($lockKey, function() use ($name) {
                $st = $this->getState($name);
                $failures = ($st['failures'] ?? 0) + 1;
                
                if ($failures >= $this->failureThreshold) {
                    $this->setState($name, 'open', $failures);
                } else {
                    // Keep in current mode but track count
                    $this->setState($name, $st['status'] === 'half_open' ? 'half_open' : 'closed', $failures);
                }
            }, 5);
            
            throw $exception;
        } finally {
            if ($hasProbeLock) {
                $this->cache->unlock($probeLockKey);
            }
        }
    }

    private function getState(string $name): array
    {
        $key = $this->stateKey($name);
        $state = $this->cache->get($key);

        if (!is_array($state)) {
            return [
                'status' => 'closed',
                'failures' => 0,
                'opened_at' => null,
            ];
        }

        return $state;
    }

    private function setState(string $name, string $status, int $failures): void
    {
        $state = [
            'status'    => $status,
            'failures'  => $failures,
            'opened_at' => $status === 'open' ? time() : null,
        ];

        // CORE-053: Convert timeout seconds to ceil-rounded minutes to prevent massive unit-leak TTL in Cache::put
        $minutes = max(1, (int) ceil($this->retryTimeoutSeconds / 60));

        $this->cache->put($this->stateKey($name), $state, $minutes);
    }

    private function stateKey(string $name): string
    {
        return "circuit_breaker:{$name}";
    }

    private function isRetryWindowExpired(array $state): bool
    {
        if (empty($state['opened_at'])) {
            return true;
        }

        return (time() - (int)$state['opened_at']) >= $this->retryTimeoutSeconds;
    }
}
