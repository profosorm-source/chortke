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
     * @param callable $operation
     * @param array|null $retryOnExceptions
     * @return mixed
     * @throws Throwable
     */
    public function execute(callable $operation, ?array $retryOnExceptions = null)
    {
        $attempt = 0;
        $delayMs = $this->initialDelayMs;

        $this->recordAttempt(); // Record the primary call in the budget

        while (true) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                $attempt++;

                if ($attempt >= $this->maxAttempts || !$this->shouldRetry($exception, $retryOnExceptions)) {
                    throw $exception;
                }

                // Enforce the cascading failure system-wide Retry Budget
                if (!$this->acquireRetryBudget()) {
                    // Refuse to execute retry and fail-fast to prevent retry storm
                    throw new \RuntimeException(
                        "Cascading failure protection: system-wide retry budget exhausted. " . $exception->getMessage(),
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
     * Check and update the system-wide retry budget.
     * Allows retries only if retries are < 10% of total calls,
     * with a minimum allowance of 5 retries for cold start.
     */
    private function acquireRetryBudget(): bool
    {
        $cache = Cache::getInstance();
        $window = 10; // 10 second sliding window/key expiration
        
        $totalCallsKey = 'retry_budget:total_calls';
        $retriesKey = 'retry_budget:retries';
        
        try {
            $totalCalls = (int)$cache->get($totalCallsKey, 0);
            $retries = (int)$cache->get($retriesKey, 0);
            
            // Cold start allowance: if total calls are low, always allow up to 5 retries
            if ($totalCalls < 50 && $retries < 5) {
                $cache->increment($retriesKey, 1, $window);
                return true;
            }
            
            // Enforce strict 10% retry budget
            if ($retries >= (int)($totalCalls * 0.10)) {
                return false; // Budget exhausted
            }
            
            // Consume budget
            $cache->increment($retriesKey, 1, $window);
            return true;
        } catch (\Throwable) {
            return true; // Safe fail-open for budget tracking failures
        }
    }

    /**
     * Record a non-retry attempt in the system-wide budget
     */
    private function recordAttempt(): void
    {
        try {
            Cache::getInstance()->increment('retry_budget:total_calls', 1, 10);
        } catch (\Throwable) {
            // Safe ignore
        }
    }

    private function shouldRetry(Throwable $exception, ?array $retryOnExceptions): bool
    {
        // CORE-050: Hard exclusions — Never retry unrecoverable logic/engine errors
        if ($exception instanceof \Error || 
            $exception instanceof \TypeError || 
            $exception instanceof \ParseError || 
            $exception instanceof \InvalidArgumentException) {
            return false;
        }

        if ($retryOnExceptions === null) {
            // Allow retrying generic user-level Exceptions but not internal fatal faults
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
