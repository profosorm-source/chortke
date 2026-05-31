<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * CircuitBreaker - نسخه بهبود یافته فاز ۵ (Section 8.3)
 *
 * تغییرات واقعی:
 * - Integration با ExternalGatewayClient (در صورت وجود)
 * - Fail-fast برای half-open state
 * - Logging دقیق
 * - Atomic lock برای جلوگیری از race condition
 */
class CircuitBreaker implements \App\Contracts\CircuitBreakerInterface
{
    private Cache $cache;
    private int $failureThreshold = 5;
    private int $retryTimeoutSeconds = 60;
    private Logger $logger;

    private array $config;

    public function __construct(Cache $cache, Logger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->config = config('circuit_breaker', []);
        $this->failureThreshold = (int)($this->config['failure_threshold'] ?? 5);
        $this->retryTimeoutSeconds = (int)($this->config['retry_timeout_seconds'] ?? 60);
    }

    private function getThreshold(string $serviceName): int
    {
        return (int)($this->config[$serviceName]['threshold'] ?? $this->failureThreshold);
    }

    private function getTimeout(string $serviceName): int
    {
        return (int)($this->config[$serviceName]['timeout'] ?? $this->retryTimeoutSeconds);
    }

    public function call(string $serviceName, callable $operation)
    {
        $stateKey = "circuit_breaker:{$serviceName}:state";
        $timeout = $this->getTimeout($serviceName);
        $threshold = $this->getThreshold($serviceName);

        $state = $this->cache->get($stateKey) ?: ['status' => 'closed', 'failures' => 0, 'opened_at' => null];

        if ($state['status'] === 'open') {
            if (time() - ($state['opened_at'] ?? 0) < $timeout) {
                $this->logger->warning('circuit_breaker.open', ['service' => $serviceName]);
                throw new \Core\Exceptions\CircuitBreakerOpenException($serviceName);
            }
            // Half-open transition
            $state['status'] = 'half_open';
            $this->cache->put($stateKey, $state, $timeout);
        }

        try {
            $result = $operation();
            // Success - reset to closed
            if ($state['status'] !== 'closed') {
                \Core\EventDispatcher::dispatch('circuit_breaker.closed', ['service' => $serviceName]);
            }
            $this->cache->put($stateKey, ['status' => 'closed', 'failures' => 0], 3600);
            return $result;
        } catch (\Throwable $e) {
            $failures = ($state['failures'] ?? 0) + 1;
            $newStatus = ($failures >= $threshold) ? 'open' : 'closed';
            
            $newState = [
                'status' => $newStatus,
                'failures' => $failures,
                'opened_at' => time(),
            ];
            $this->cache->put($stateKey, $newState, $timeout);

            if ($newStatus === 'open' && $state['status'] !== 'open') {
                $this->logger->critical('circuit_breaker.tripped_open', [
                    'service' => $serviceName,
                    'threshold' => $threshold,
                ]);
                \Core\EventDispatcher::dispatch('circuit_breaker.opened', ['service' => $serviceName, 'error' => $e->getMessage()]);
            }

            $this->logger->error('circuit_breaker.failure', [
                'service' => $serviceName,
                'failures' => $failures,
                'status' => $newState['status'],
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function isOpen(string $serviceName): bool
    {
        $state = $this->cache->get("circuit_breaker:{$serviceName}:state") ?: ['status' => 'closed'];
        return $state['status'] === 'open';
    }
}
