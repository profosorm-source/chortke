<?php

/**
 * توابع کمکی محدودیت تعداد درخواست (Rate Limiting)
 */

if (!function_exists('get_rate_limit_config')) {
    function get_rate_limit_config(string $group, string $endpoint = 'general'): array
    {
        $default = config('rate_limits.default') ?? [
            'max_attempts' => 60,
            'decay_minutes' => 1,
        ];

        $config = config("rate_limits.{$group}.{$endpoint}");
        if (is_array($config) && $config !== []) {
            return $config;
        }

        $groupConfig = config("rate_limits.{$group}");
        if (is_array($groupConfig) && !isset($groupConfig['max_attempts'])) {
            return $groupConfig['general'] ?? $default;
        }

        return is_array($groupConfig) ? $groupConfig : $default;
    }
}

if (!function_exists('check_rate_limit')) {
    function check_rate_limit(string $key, array $config): array
    {
        $limiter = app(\Core\RateLimiter::class);
        $maxAttempts = (int)($config['max_attempts'] ?? 60);
        $decayMinutes = (int)($config['decay_minutes'] ?? 1);

        $currentHits = $limiter->hits($key);
        if ($currentHits >= $maxAttempts) {
            $retryAfter = $limiter->availableIn($key);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => time() + $retryAfter,
                'retry_after' => $retryAfter,
                'message' => $config['message'] ?? 'تعداد درخواست‌ها بیش از حد مجاز است.',
            ];
        }

        $allowed = $limiter->attempt($key, $maxAttempts, $decayMinutes);
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $maxAttempts - $limiter->hits($key)),
            'reset_at' => time() + ($decayMinutes * 60),
            'retry_after' => 0,
            'message' => null,
        ];
    }
}

if (!function_exists('rate_limit')) {
    function rate_limit(string $group, string $endpoint = 'general', ?string $identifier = null): void
    {
        $config = get_rate_limit_config($group, $endpoint);
        $identifier = $identifier ?? get_client_ip();
        $key = "rl:{$group}:{$endpoint}:{$identifier}";
        
        $result = check_rate_limit($key, $config);
        if (!$result['allowed']) {
            throw new \Exception($result['message'], 429);
        }
    }
}
