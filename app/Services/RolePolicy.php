<?php

namespace App\Services;

/**
 * RolePolicy - سیاست متمرکز نقش‌ها و دسترسی‌ها
 */
class RolePolicy
{
    public const ROLES = [
        'user' => 1,
        'admin' => 2,
        'super_admin' => 3,
        'support' => 4,
    ];

    public const ADMIN_ROLES = ['admin', 'super_admin', 'support'];

    public const FULL_ADMIN_ROLES = ['admin', 'super_admin'];

    /**
     * آیا نقش admin است (شامل support)
     */
    public static function isAdmin(string $role): bool
    {
        return in_array($role, self::ADMIN_ROLES, true);
    }

    /**
     * آیا نقش admin کامل است (بدون support)
     */
    public static function isFullAdmin(string $role): bool
    {
        return in_array($role, self::FULL_ADMIN_ROLES, true);
    }

    /**
     * آیا نقش مجاز برای دسترسی خاص است
     */
    public static function hasPermission(string $role, string $permission): bool
    {
        if ($role === 'super_admin') {
            return true;
        }

        $permissions = [
            'admin' => ['manage_users', 'manage_withdrawals', 'view_reports'],
            'support' => ['view_users', 'view_reports'],
        ];

        return in_array($permission, $permissions[$role] ?? [], true);
    }
}