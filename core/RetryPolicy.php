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

        while (true) {
            try {
                return $operation();
            } catch (Throwable $exception) {
                $attempt++;

                if ($attempt >= $this->maxAttempts || !$this->shouldRetry($exception, $retryOnExceptions)) {
                    throw $exception;
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
