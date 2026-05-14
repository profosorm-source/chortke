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
        $state = $this->getState($name);

        if ($state['status'] === 'open') {
            if ($this->isRetryWindowExpired($state)) {
                $this->setState($name, 'half_open', 0);
            } else {
                throw new RuntimeException("Circuit breaker '{$name}' is open");
            }
        }

        try {
            $result = $operation();
            $this->setState($name, 'closed', 0);
            return $result;
        } catch (\Throwable $exception) {
            $failures = $state['failures'] + 1;
            if ($failures >= $this->failureThreshold) {
                $this->setState($name, 'open', $failures);
            } else {
                $this->setState($name, 'closed', $failures);
            }
            throw $exception;
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
            'status' => $status,
            'failures' => $failures,
            'opened_at' => $status === 'open' ? time() : null,
        ];

        $this->cache->put($this->stateKey($name), $state, $this->retryTimeoutSeconds);
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
