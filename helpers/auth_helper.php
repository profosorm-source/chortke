<?php

use Core\Session;

if (!function_exists('auth')) {
    function auth(): ?object
    {
        $userId = user_id();
        if (!$userId) return null;
        
        return app(\App\Services\User\UserService::class)->findById($userId);
    }
}

if (!function_exists('auth_user')) {
    function auth_user(): ?object
    {
        return auth();
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
