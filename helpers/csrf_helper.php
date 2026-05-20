<?php

/**
 * توابع کمکی امنیتی CSRF
 */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return app(\Core\CSRF::class)->generateToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $tokenName = config('csrf.token_name') ?? '_csrf_token';
        return '<input type="hidden" name="' . e($tokenName) . '" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_token_for')) {
    /**
     * CORE-036: تولید توکن برای یک اکشن خاص حساس به زمان یا محتوا
     */
    function csrf_token_for(string $action): string
    {
        return app(\Core\CSRF::class)->generateTokenFor($action);
    }
}

if (!function_exists('csrf_field_for')) {
    /**
     * CORE-036: فیلد مخفی برای توکن اکشن خاص
     */
    function csrf_field_for(string $action): string
    {
        return '<input type="hidden" name="_csrf_token_action" value="' . e(csrf_token_for($action)) . '">';
    }
}
