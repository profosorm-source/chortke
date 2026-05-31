<?php

declare(strict_types=1);

namespace Core;

use Core\Strategies\FixedWindowStrategy;
use Core\Strategies\TokenBucketStrategy;
use Core\Strategies\SlidingWindowStrategy;
use App\Contracts\RateLimiterInterface;
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
class RateLimiter implements RateLimiterInterface
{
    private RateLimitStrategy $strategy;
    /** Current strategy name (e.g. 'fixed_window') */
    private string $strategyName = 'fixed_window';
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
        $this->strategyName = $name;
        return $this;
    }

    /**
     * بازگرداندن نام استراتژی فعلی برای استفاده در middleware
     */
    public function getStrategy(): string
    {
        return $this->strategyName;
    }

    /**
     * تلاش یکپارچه با policyهای مختلف
     */
    public function attempt(string $key, ?int $maxAttempts = null, ?int $decayMinutes = null, bool $failClosed = false): bool
    {
        $maxAttempts = $maxAttempts ?? 60;
        $decayMinutes = $decayMinutes ?? 1;

        try {
            $allowed = $this->strategy->attempt($key, $maxAttempts, $decayMinutes);

            if (!$allowed) {
                $this->antiFraudService->recordRateLimitExceeded($key);
                $this->logger->warning('rate_limit.exceeded', [
                    'key' => $key,
                    'max' => $maxAttempts,
                    'decay' => $decayMinutes
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
     * شکل‌دهی ترافیک (Traffic Shaping)
     * به جای بلاک کردن فوری (429)، اگر سرعت کاربر از حد مشخصی گذشت، ریکوئست او را کمی با تاخیر مواجه می‌کند.
     * این تکنیک برای مقابله با بات‌ها و اسپم‌ها بدون آزار دادن کاربر واقعی عالی است.
     */
    public function throttle(string $key, int $maxAttempts = 60, int $decaySeconds = 60): bool
    {
        $allowed = $this->attempt($key, $maxAttempts, $decaySeconds);
        
        if (!$allowed) {
            return false;
        }
        
        // محاسبه میزان فشار
        $attempts = $this->getAttempts($key);
        $threshold = $maxAttempts * 0.5; // از 50% ظرفیت به بعد شروع به کند کردن می‌کنیم
        
        if ($attempts > $threshold) {
            // هر چه به سقف نزدیک‌تر شود، زمان انتظار بیشتر می‌شود
            // مثال: حد 60، تلاش 55 -> تاخیر 500 میلی ثانیه
            // فرمول: (تلاش فعلی - آستانه) / (سقف - آستانه) * حداکثر تاخیر
            $penaltyFactor = ($attempts - $threshold) / ($maxAttempts - $threshold);
            $sleepMs = (int) ($penaltyFactor * 1000); // Max 1000ms delay
            
            if ($sleepMs > 0) {
                // تاخیر عمدی برای خنثی کردن بات‌ها
                usleep($sleepMs * 1000); // تبدیل به میکروثانیه
                
                // لاگینگ فقط برای مواردی که تاخیر زیاد است تا فایل لاگ پر نشود
                if ($sleepMs >= 500) {
                    $this->logger->info('traffic_shaping.applied', [
                        'key' => $key,
                        'delay_ms' => $sleepMs,
                        'attempts' => $attempts
                    ]);
                }
            }
        }
        
        return true;
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

    public function hits(string $key): int
    {
        return $this->getAttempts($key);
    }

    public function availableIn(string $key): int
    {
        return $this->strategy->availableIn($key);
    }

    public function clear(string $key): void
    {
        $this->strategy->clear($key);
    }

    public function incrementAttempts(string $key, int $decayMinutes): void
    {
        try {
            $this->attempt($key, PHP_INT_MAX, $decayMinutes);
        } catch (\Throwable $e) {
            $this->logger->error('rate_limiter.increment_failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function checkLoginAttempt(string $identifier): array
    {
        $config = config('rate_limits.admin.login', [
            'max_attempts' => 3,
            'decay_minutes' => 10,
            'message' => 'تعداد تلاش‌های ورود ادمین بیش از حد. لطفاً 10 دقیقه صبر کنید.',
        ]);

        $maxAttempts = (int) ($config['max_attempts'] ?? 3);
        $decayMinutes = (int) ($config['decay_minutes'] ?? 10);
        $message = $config['message'] ?? 'تعداد تلاش‌های ورود ادمین بیش از حد. لطفاً دوباره بعدا تلاش کنید.';

        $currentHits = $this->hits($identifier);
        if ($currentHits >= $maxAttempts) {
            $retryAfter = $this->availableIn($identifier);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => time() + $retryAfter,
                'retry_after' => $retryAfter,
                'message' => $message,
            ];
        }

        $allowed = $this->attempt($identifier, $maxAttempts, $decayMinutes);
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $maxAttempts - $this->hits($identifier)),
            'reset_at' => time() + ($decayMinutes * 60),
            'retry_after' => $allowed ? 0 : $this->availableIn($identifier),
            'message' => $allowed ? null : $message,
        ];
    }

    public function clearLoginAttempts(string $identifier): void
    {
        $this->clear($identifier);
    }

    public function checkApiLimit(int $userId, int $maxRequests = 60, int $perMinutes = 1): array
    {
        $key = "api:{$userId}";
        $currentHits = $this->hits($key);

        if ($currentHits >= $maxRequests) {
            $retryAfter = $this->availableIn($key);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => time() + $retryAfter,
                'retry_after' => $retryAfter,
                'message' => 'تعداد درخواست‌های API بیش از حد مجاز است. لطفاً بعداً تلاش کنید.',
            ];
        }

        $allowed = $this->attempt($key, $maxRequests, $perMinutes);
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $maxRequests - $this->hits($key)),
            'reset_at' => time() + ($perMinutes * 60),
            'retry_after' => $allowed ? 0 : $this->availableIn($key),
            'message' => $allowed ? null : 'تعداد درخواست‌های API بیش از حد مجاز است. لطفاً بعداً تلاش کنید.',
        ];
    }

    public function cleanup(): int
    {
        if (method_exists($this->strategy, 'cleanup')) {
            return $this->strategy->cleanup() ?? 0;
        }

        return 0;
    }
}
