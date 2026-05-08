<?php

use Core\Session;

if (!function_exists('auth')) {
    function auth(): ?object
    {
        static $cached = null;
        static $cachedUserId = null;
        static $service = null;

        $session = \Core\Session::getInstance();
        $currentUserId = $session->has('user_id') ? (int)$session->get('user_id') : null;
        
        // ✅ اگر user تغییر کرده یا logout کرده، cache reset کن
        if ($currentUserId !== $cachedUserId) {
            $cached = null;
            $cachedUserId = $currentUserId;
        }

        if ($cached !== null) {
            return $cached;
        }

        if ($currentUserId === null) {
            return null;
        }

        if ($service === null) {
            $service = app(\App\Services\User\UserService::class);
        }

        $cached = $service->findById($currentUserId) ?: null;

        return $cached;
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?object
    {
        return auth();
    }
}

// ✅ تابع جدید برای logout - cache را invalid کن
if (!function_exists('logout_user')) {
    function logout_user()
    {
        $session = \Core\Session::getInstance();
        $session->destroy();
        
        // امن‌تر: ساخت سشن جدید برای جلوگیری از Fixation
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

function user_id(): ?int
{
    $session = Session::getInstance();
    $id = $session->get('user_id');
    return $id ? (int)$id : null;
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        $session = Session::getInstance();
        return ($session->get('user_role') === 'admin');
    }
}

function is_kyc_verified(?int $userId = null): bool
{
    $userId = $userId ?? user_id();
    if (!$userId) return false;

    return app(\App\Services\User\UserService::class)->isKycVerified($userId);
}
