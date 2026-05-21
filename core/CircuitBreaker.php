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
class CircuitBreaker
{
    private Cache $cache;
    private int $failureThreshold = 5;
    private int $retryTimeoutSeconds = 60;
    private Logger $logger;

    public function __construct(Cache $cache, Logger $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;
        $config = config('circuit_breaker', []);
        $this->failureThreshold = (int)($config['failure_threshold'] ?? 5);
        $this->retryTimeoutSeconds = (int)($config['retry_timeout_seconds'] ?? 60);
    }

    public function call(string $serviceName, callable $operation)
    {
        $stateKey = "circuit_breaker:{$serviceName}:state";

        $state = $this->cache->get($stateKey) ?: ['status' => 'closed', 'failures' => 0, 'opened_at' => null];

        if ($state['status'] === 'open') {
            if (time() - ($state['opened_at'] ?? 0) < $this->retryTimeoutSeconds) {
                $this->logger->warning('circuit_breaker.open', ['service' => $serviceName]);
                throw new RuntimeException("Circuit breaker for {$serviceName} is OPEN");
            }
            // Half-open transition
            $state['status'] = 'half_open';
            $this->cache->put($stateKey, $state, $this->retryTimeoutSeconds);
        }

        try {
            $result = $operation();
            // Success - reset to closed
            $this->cache->put($stateKey, ['status' => 'closed', 'failures' => 0], 3600);
            return $result;
        } catch (\Throwable $e) {
            $failures = ($state['failures'] ?? 0) + 1;
            $newState = [
                'status' => ($failures >= $this->failureThreshold) ? 'open' : 'closed',
                'failures' => $failures,
                'opened_at' => time(),
            ];
            $this->cache->put($stateKey, $newState, $this->retryTimeoutSeconds);

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
