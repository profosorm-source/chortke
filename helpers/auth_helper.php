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

/**
 * Centralized password verification helper
 */
if (!function_exists('verify_user_password')) {
    function verify_user_password(string $password, string $hash, ?int $userId = null): bool
    {
        if ($password === '') return false;
        
        $inputPassword = base64_encode(hash('sha384', $password, true));
        
        if (password_verify($inputPassword, $hash)) {
            return true;
        }

        // Fallback for legacy passwords
        if (password_verify($password, $hash)) {
            if ($userId) {
                // Resolve UserService via Container to trigger auto-rehash
                try {
                    $userService = \Core\Application::getInstance()->container->make(\App\Services\User\UserService::class);
                    $userService->changePassword($userId, $password);
                } catch (\Throwable $e) {
                    // Silently fail if UserService cannot be resolved (e.g. during early boot)
                }
            }
            return true;
        }

        return false;
    }
}
