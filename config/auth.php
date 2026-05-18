<?php
/**
 * Authentication configuration
 *
 * This file centralizes auth-specific settings used by password policy,
 * login risk, and account security features.
 */

return [
    'password' => [
        'min_length' => env('AUTH_PASSWORD_MIN_LENGTH', 12),
        'max_length' => env('AUTH_PASSWORD_MAX_LENGTH', 128),
        'require_uppercase' => env('AUTH_PASSWORD_REQUIRE_UPPERCASE', true),
        'require_lowercase' => env('AUTH_PASSWORD_REQUIRE_LOWERCASE', true),
        'require_numbers' => env('AUTH_PASSWORD_REQUIRE_NUMBERS', true),
        'require_special_chars' => env('AUTH_PASSWORD_REQUIRE_SPECIAL_CHARS', true),
        'prevent_common' => env('AUTH_PASSWORD_PREVENT_COMMON', true),
        'check_hibp' => env('AUTH_PASSWORD_CHECK_HIBP', true),
    ],

    'max_concurrent_sessions' => env('AUTH_MAX_CONCURRENT_SESSIONS', 5),
    'risk_cache_key' => env('AUTH_RISK_CACHE_KEY', secure_key()),
];
