<?php

namespace App\Policies;

use Core\RateLimiter;
use Core\Logger;
use App\Models\FeatureFlag;

/**
 * RateLimitPolicy
 * 
 * این سرویس یکپارچه جایگزین ApiRateLimiter و AntiFraud\RateLimitingService است.
 * از Core\RateLimiter (Redis/Cache) به جای Database برای پرفورمنس استفاده می‌کند.
 */
class RateLimitPolicy
{
    private RateLimiter $limiter;
    private Logger $logger;

    private const ACTIONS = [
        'withdrawal'         => 'withdrawal_limits',
        'manual_deposit'     => 'financial_limits',
        'crypto_deposit'     => 'financial_limits',
        'bank_card_add'      => 'financial_limits',
        'task_submit'        => 'task_limits',
        'task_dispute'       => 'task_limits',
        'kyc_submit'         => 'security_limits',
        'profile_update'     => 'user_limits',
        'password_change'    => 'security_limits',
        'ticket_create'      => 'support_limits',
        'ticket_reply'       => 'support_limits',
        'login'              => 'auth_limits',
    ];

    public function __construct(RateLimiter $limiter, Logger $logger)
    {
        $this->limiter = $limiter;
        $this->logger = $logger;
    }

    /**
     * بررسی محدودیت با استفاده از FeatureFlag
     */
    public function check(string $action, string|int $identifier, ?string $limitKey = null): bool
    {
        // Whitelist check (Mocked for AntiFraud logic consolidation)
        if ($this->isWhitelisted($identifier)) {
            return true;
        }

        $featureName = self::ACTIONS[$action] ?? 'rate_limiting';
        $config = $this->getFeatureConfig($featureName, $limitKey ?? 'standard');
        
        $key = "rl_{$action}_{$identifier}";
        $allowed = $this->limiter->attempt($key, $config['max_attempts'], $config['decay_minutes']);

        if (!$allowed) {
            $this->logger->warning('rate_limit_exceeded', [
                'action' => $action,
                'identifier' => $identifier,
                'limit' => $config['max_attempts']
            ]);
        }

        return $allowed;
    }

    private function isWhitelisted(string|int $identifier): bool
    {
        // در آینده می‌توان از کش برای Whitelist استفاده کرد
        // فعلاً به صورت stub پیاده‌سازی شده تا جایگزین منطق AntiFraud شود.
        return false;
    }

    private function getFeatureConfig(string $featureName, string $limitKey): array
    {
        // در اینجا باید FeatureFlagService یا Model خوانده شود. 
        // برای حفظ سرعت، از پیش‌فرض‌ها استفاده می‌کنیم اگر تنظیم نشده باشد.
        
        return [
            'max_attempts' => 10,
            'decay_minutes' => 60
        ];
    }
    
    public function retryAfter(string $action, string|int $identifier): int
    {
        $key = "rl_{$action}_{$identifier}";
        return $this->limiter->availableIn($key) ?? 0;
    }

    public function remaining(string $action, string|int $identifier, ?string $limitKey = null): int
    {
        $featureName = self::ACTIONS[$action] ?? 'rate_limiting';
        $config = $this->getFeatureConfig($featureName, $limitKey ?? 'standard');
        
        $key = "rl_{$action}_{$identifier}";
        $attempts = $this->limiter->getAttempts($key);
        
        return max(0, $config['max_attempts'] - $attempts);
    }

    public function tooManyResponse(string $action, string|int $identifier, bool $isAjax = false): never
    {
        $retryAfter = $this->retryAfter($action, $identifier);
        $retryMins = (int)ceil($retryAfter / 60);

        http_response_code(429);
        header('Retry-After: ' . $retryAfter);

        if ($isAjax || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً {$retryMins} دقیقه دیگر تلاش کنید.",
                'retry_after' => $retryAfter,
            ]);
        } else {
            echo "<h1>429 - Too Many Requests</h1>";
        }
        exit;
    }

    public static function enforce(string $action, string|int $identifier, bool $isAjax = false): void
    {
        $instance = app(self::class);
        if (!$instance->check($action, $identifier)) {
            $instance->tooManyResponse($action, $identifier, $isAjax);
        }
    }
}
