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
    private ?RateLimiter $limiter = null;
    private Logger $logger;
    private ?\App\Models\FeatureFlag $featureFlagModel;

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

    public function __construct(Logger $logger, ?\App\Models\FeatureFlag $featureFlagModel = null)
    {
        $this->logger = $logger;
        $this->featureFlagModel = $featureFlagModel;
    }

    private function getLimiter(): RateLimiter
    {
        if ($this->limiter === null) {
            $this->limiter = \Core\Container::getInstance()->make(RateLimiter::class);
        }
        return $this->limiter;
    }

    /**
     * بررسی محدودیت با استفاده از FeatureFlag
     */
    public function check(string $action, string|int $identifier, ?string $limitKey = null): bool
    {
        if ($this->isWhitelisted($identifier)) {
            return true;
        }

        $config = $this->resolveActionConfig($action, $limitKey);

        $key = "rl_{$action}_{$identifier}";
        $allowed = $this->getLimiter()->attempt($key, $config['max_attempts'], $config['decay_minutes']);

        if (!$allowed) {
            $this->logger->warning('rate_limit_exceeded', [
                'action'     => $action,
                'identifier' => $identifier,
                'limit'      => $config['max_attempts'],
                'window_min' => $config['decay_minutes'],
            ]);
        }
        return $allowed;
    }

    private function isWhitelisted(string|int $identifier): bool
    {
        $candidate = (string)$identifier;

        $whitelist = config('rate_limits.whitelist', []);
        if (is_array($whitelist) && in_array($candidate, array_map('strval', $whitelist), true)) {
            return true;
        }

        if ($this->featureFlagModel) {
            try {
                $flag = $this->featureFlagModel->findByName('rate_limit_whitelist');
                if ($flag && !empty($flag->metadata)) {
                    $metadata = json_decode($flag->metadata, true);
                    if (is_array($metadata) && isset($metadata['whitelist']) && is_array($metadata['whitelist'])) {
                        if (in_array($candidate, array_map('strval', $metadata['whitelist']), true)) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('rate_limit.whitelist_lookup_failed', [
                    'identifier' => $candidate,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    private function getFeatureConfig(string $featureName, string $limitKey): array
    {
        // 1) Highest priority: ops-controlled FeatureFlag (live overrides without deploy).
        if ($this->featureFlagModel) {
            try {
                $flag = $this->featureFlagModel->findByName($featureName);
                if ($flag) {
                    $metadata = json_decode($flag->metadata ?? '{}', true);
                    if (is_array($metadata) && isset($metadata[$limitKey])) {
                        return [
                            'max_attempts'  => (int)($metadata[$limitKey]['max'] ?? 5),
                            'decay_minutes' => (int)($metadata[$limitKey]['window'] ?? 60),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Don't fail-closed silently here — drop to next source.
                $this->logger->warning('rate_limit.feature_flag_lookup_failed', [
                    'feature' => $featureName,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // 2) Section 8.8 — Fallback to config/rate_limits.php (single source of truth).
        //    The legacy 'standard'/'standard' lookup path is preserved for old callers,
        //    but new callers should pass the action name and let resolveByAction() find
        //    the right (group, endpoint) pair via the action_map.
        $cfg = config("rate_limits.{$featureName}");
        if (is_array($cfg)) {
            // grouped: pick $limitKey or its first nested entry
            if (isset($cfg[$limitKey]) && is_array($cfg[$limitKey]) && isset($cfg[$limitKey]['max_attempts'])) {
                return [
                    'max_attempts'  => (int)$cfg[$limitKey]['max_attempts'],
                    'decay_minutes' => (int)$cfg[$limitKey]['decay_minutes'],
                ];
            }
            if (isset($cfg['max_attempts'])) {
                return [
                    'max_attempts'  => (int)$cfg['max_attempts'],
                    'decay_minutes' => (int)$cfg['decay_minutes'],
                ];
            }
        }

        // 3) Default from config (instead of hard restrictive 3/24h).
        $default = config('rate_limits.default', ['max_attempts' => 60, 'decay_minutes' => 1]);
        return [
            'max_attempts'  => (int)($default['max_attempts'] ?? 60),
            'decay_minutes' => (int)($default['decay_minutes'] ?? 1),
        ];
    }

    /**
     * Section 8.8 — Resolve config for an action using config('rate_limits.action_map').
     * Used by check() / retryAfter() / remaining() to avoid duplicating the
     * old hardcoded ACTIONS constant on every change.
     */
    private function resolveActionConfig(string $action, ?string $limitKey): array
    {
        $map = config('rate_limits.action_map');
        if (is_array($map) && isset($map[$action]) && is_array($map[$action])) {
            $group    = (string)($map[$action][0] ?? '');
            $endpoint = (string)($map[$action][1] ?? ($limitKey ?? 'general'));
            $cfg = config("rate_limits.{$group}.{$endpoint}");
            if (is_array($cfg) && isset($cfg['max_attempts'])) {
                return [
                    'max_attempts'  => (int)$cfg['max_attempts'],
                    'decay_minutes' => (int)$cfg['decay_minutes'],
                    'message'       => $cfg['message'] ?? null,
                ];
            }
        }

        // Backward-compatible path: legacy ACTIONS constant + FeatureFlag.
        $featureName = self::ACTIONS[$action] ?? 'rate_limiting';
        $cfg = $this->getFeatureConfig($featureName, $limitKey ?? 'standard');
        return [
            'max_attempts'  => (int)$cfg['max_attempts'],
            'decay_minutes' => (int)$cfg['decay_minutes'],
            'message'       => null,
        ];
    }
    
    public function retryAfter(string $action, string|int $identifier): int
    {
        $key = "rl_{$action}_{$identifier}";
        return $this->getLimiter()->availableIn($key) ?? 0;
    }

    public function remaining(string $action, string|int $identifier, ?string $limitKey = null): int
    {
        $config = $this->resolveActionConfig($action, $limitKey);
        $key = "rl_{$action}_{$identifier}";
        $attempts = $this->getLimiter()->getAttempts($key);
        return max(0, (int)$config['max_attempts'] - (int)$attempts);
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
