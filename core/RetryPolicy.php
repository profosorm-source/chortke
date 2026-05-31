<?php

declare(strict_types=1);

namespace Core;

use Throwable;

class RetryPolicy
{
    private int $maxAttempts;
    private int $initialDelayMs;
    private int $multiplier;
    private int $maxDelayMs;

    public function __construct()
    {
        $config = config('retry_policy', []);

        $this->maxAttempts = (int)($config['max_attempts'] ?? 3);
        $this->initialDelayMs = (int)($config['initial_delay_ms'] ?? 100);
        $this->multiplier = (int)($config['multiplier'] ?? 2);
        $this->maxDelayMs = (int)($config['max_delay_ms'] ?? 2000);
    }

    /**
     * اجرای یک عملیات با retry و exponential backoff.
     *
     * @param string $context
     * @param callable $operation
     * @param array|null $retryOnExceptions
     * @return mixed
     * @throws Throwable
     */
    public function executeWithContext(string $context, callable $operation, ?array $retryOnExceptions = null)
    {
        $attempt = 0;
        $delayMs = $this->initialDelayMs;

        self::recordAttempt($context); // Record the primary call in the budget

        $circuitBreaker = null;
        try {
            if (class_exists(Container::class)) {
                $circuitBreaker = Container::getInstance()->make(CircuitBreaker::class);
            }
        } catch (\Throwable $e) {}

        while (true) {
            // Fail-fast if circuit breaker is open (for services that use it)
            if ($circuitBreaker && $circuitBreaker->isOpen($context)) {
                throw new \RuntimeException(
                    "Fail-fast: Circuit Breaker is OPEN for context {$context}.",
                    503
                );
            }

            try {
                return $operation();
            } catch (Throwable $exception) {
                $attempt++;

                if ($attempt >= $this->maxAttempts || !$this->shouldRetry($exception, $retryOnExceptions)) {
                    throw $exception;
                }

                // Enforce the cascading failure system-wide Retry Budget
                if (!self::acquireRetryBudget($context)) {
                    if (isset($this->logger)) { $this->logger->alert("retry_budget_exhausted", [
                            'context' => $context,
                            'error' => $exception->getMessage()
                        ]); }
                    // Refuse to execute retry and fail-fast to prevent retry storm
                    throw new \RuntimeException(
                        "Cascading failure protection: system-wide retry budget exhausted for context {$context}. " . $exception->getMessage(),
                        503,
                        $exception
                    );
                }

                // CORE-051: Apply random jitter (0.8x to 1.2x) to avoid synchronized retry storms
                $sleepMs = min($delayMs, $this->maxDelayMs);
                $jitterFactor = mt_rand(800, 1200) / 1000.0;
                $sleepWithJitter = max(1, (int)($sleepMs * $jitterFactor));

                usleep($sleepWithJitter * 1000);
                $delayMs = min($delayMs * $this->multiplier, $this->maxDelayMs);
            }
        }
    }

    /**
     * Backward compatibility. Use executeWithContext instead.
     */
    public function execute(callable $operation, ?array $retryOnExceptions = null)
    {
        return $this->executeWithContext('global', $operation, $retryOnExceptions);
    }

    /**
     * Check and update the system-wide retry budget.
     */
    public static function acquireRetryBudget(string $context = 'global'): bool
    {
        $cache = Cache::getInstance();
        $resilienceConfig = config('resilience.retry_budget', []);
        
        $allowedPercentage = (int)($resilienceConfig['contexts'][$context]['allowed_percentage'] 
                             ?? $resilienceConfig['global']['allowed_percentage'] 
                             ?? 10);
                             
        $minAllowance = (int)($resilienceConfig['contexts'][$context]['min_allowance'] 
                             ?? $resilienceConfig['global']['min_allowance'] 
                             ?? 5);
        
        try {
            $currentTime = time();
            $totalCalls = 0;
            $retries = 0;
            
            // Sliding window 10 seconds
            for ($i = 0; $i < 10; $i++) {
                $bucket = ($currentTime - $i) % 10;
                $totalCalls += (int)$cache->get("retry_budget:{$context}:total_calls:{$bucket}", 0);
                $retries += (int)$cache->get("retry_budget:{$context}:retries:{$bucket}", 0);
            }
            
            // Cold start allowance
            if ($totalCalls < 50 && $retries < $minAllowance) {
                $currentBucket = $currentTime % 10;
                $cache->increment("retry_budget:{$context}:retries:{$currentBucket}", 1, 10);
                return true;
            }
            
            // Percentage limit
            if ($retries >= (int)($totalCalls * ($allowedPercentage / 100))) {
                return false; 
            }
            
            $currentBucket = $currentTime % 10;
            $cache->increment("retry_budget:{$context}:retries:{$currentBucket}", 1, 10);
            return true;
        } catch (\Throwable) {
            // Probabilistic Load Shedding fallback
            return mt_rand(1, 100) <= $allowedPercentage;
        }
    }

    /**
     * Record a non-retry attempt in the system-wide budget
     */
    public static function recordAttempt(string $context = 'global'): void
    {
        try {
            $currentBucket = time() % 10;
            Cache::getInstance()->increment("retry_budget:{$context}:total_calls:{$currentBucket}", 1, 10);
        } catch (\Throwable) {
            // Safe ignore
        }
    }

    private function shouldRetry(Throwable $exception, ?array $retryOnExceptions): bool
    {
        if ($exception instanceof \Error || 
            $exception instanceof \TypeError || 
            $exception instanceof \ParseError || 
            $exception instanceof \InvalidArgumentException) {
            return false;
        }

        if ($retryOnExceptions === null) {
            return $exception instanceof \Exception;
        }

        foreach ($retryOnExceptions as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }
}
