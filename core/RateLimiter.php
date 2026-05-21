<?php

declare(strict_types=1);

namespace Core;

use Core\Strategies\FixedWindowStrategy;
use Core\Strategies\TokenBucketStrategy;
use Core\Strategies\SlidingWindowStrategy;
use App\Services\AntiFraud\RateLimitingService;
use Core\Logger;

/**
 * RateLimiter - نسخه بهبود یافته فاز ۵ (Section 8.8)
 *
 * تغییرات:
 * - Unified policy برای نقاط مختلف (financial, search, task, auth, api)
 * - ادغام با AntiFraud RateLimitingService
 * - Fail-closed برای مسیرهای حساس
 * - Logging یکپارچه
 */
class RateLimiter
{
    private RateLimitStrategy $strategy;
    private Cache $cache;
    private EventDispatcher $eventDispatcher;
    private RateLimitingService $antiFraudService;
    private Logger $logger;

    public function __construct(
        Cache $cache,
        EventDispatcher $eventDispatcher,
        RateLimitingService $antiFraudService,
        Logger $logger,
        string $strategy = 'fixed_window'
    ) {
        $this->cache = $cache;
        $this->eventDispatcher = $eventDispatcher;
        $this->antiFraudService = $antiFraudService;
        $this->logger = $logger;
        $this->setStrategy($strategy);
    }

    public function setStrategy(string $name): self
    {
        $this->strategy = match($name) {
            'fixed_window' => new FixedWindowStrategy($this->cache),
            'token_bucket' => new TokenBucketStrategy($this->cache),
            'sliding_window' => new SlidingWindowStrategy($this->cache),
            default => throw new \InvalidArgumentException("Unknown strategy: $name"),
        };
        return $this;
    }

    /**
     * تلاش یکپارچه با policyهای مختلف
     */
    public function attempt(string $key, int $maxAttempts = 60, int $decaySeconds = 60, bool $failClosed = false): bool
    {
        try {
            $allowed = $this->strategy->attempt($key, $maxAttempts, $decaySeconds);

            if (!$allowed) {
                $this->antiFraudService->recordRateLimitExceeded($key);
                $this->logger->warning('rate_limit.exceeded', [
                    'key' => $key,
                    'max' => $maxAttempts,
                    'decay' => $decaySeconds
                ]);

                $this->eventDispatcher->dispatch('rate_limit.exceeded', [
                    'key' => $key,
                    'ip' => get_client_ip() ?? 'unknown'
                ]);
            }

            return $allowed;

        } catch (\Throwable $e) {
            $this->logger->error('rate_limiter.failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $failClosed ? false : true; // Graceful degradation
        }
    }

    /**
     * Rate Limit مخصوص عملیات مالی (حساس)
     */
    public function financial(string $action, int $userId): bool
    {
        $key = "financial:{$action}:{$userId}";
        return $this->attempt($key, 5, 60, true); // Fail-closed برای مالی
    }

    /**
     * Rate Limit برای جستجو (DB heavy)
     */
    public function search(int $userId): bool
    {
        $key = "search:user:{$userId}";
        return $this->attempt($key, 20, 60);
    }

    /**
     * Rate Limit برای تسک‌های اجتماعی
     */
    public function socialTask(int $userId): bool
    {
        $key = "socialtask:{$userId}";
        return $this->attempt($key, 15, 60);
    }

    public function getAttempts(string $key): int
    {
        return $this->strategy->getAttempts($key);
    }

    public function availableIn(string $key): int
    {
        return $this->strategy->availableIn($key);
    }

    public function clear(string $key): void
    {
        $this->strategy->clear($key);
    }

    public function cleanup(): int
    {
        return $this->strategy->cleanup() ?? 0;
    }
}
