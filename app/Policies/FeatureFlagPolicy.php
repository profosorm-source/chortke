<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Feature Flag Policy
 * 
 * کنترل دسترسی برای مدیریت Feature Flags
 * فقط admin‌ها می‌توانند feature flags را مدیریت کنند
 * super admin می‌تواند همه کاری را انجام دهد
 */
class FeatureFlagPolicy
{
    /**
     * آیا کاربر می‌تواند feature flags را ببیند؟
     */
    public function view(?User $user): bool
    {
        return $user && in_array($user->role, ['admin', 'super_admin']);
    }

    /**
     * آیا کاربر می‌تواند feature flags را ایجاد کند؟
     */
    public function create(?User $user): bool
    {
        return $user && $user->role === 'super_admin';
    }

    /**
     * آیا کاربر می‌تواند یک feature flag را ویرایش کند؟
     */
    public function update(?User $user, object $featureFlag): bool
    {
        if (!$user || !in_array($user->role, ['admin', 'super_admin'])) {
            return false;
        }

        // اگر admin است (نه super_admin)، فقط می‌تواند اگر owner باشد
        if ($user->role === 'admin') {
            return (int)$featureFlag->owner_user_id === (int)$user->id;
        }

        return true; // super_admin می‌تواند همه را ویرایش کند
    }

    /**
     * آیا کاربر می‌تواند یک feature flag را حذف کند؟
     */
    public function delete(?User $user, object $featureFlag): bool
    {
        if (!$user || $user->role !== 'super_admin') {
            return false;
        }

        return true; // فقط super_admin می‌تواند حذف کند
    }

    /**
     * آیا کاربر می‌تواند feature flag را تأیید کند؟
     */
    public function approve(?User $user, object $featureFlag): bool
    {
        return $user && $user->role === 'super_admin';
    }

    /**
     * آیا کاربر می‌تواند targeting تغییر دهد؟
     */
    public function updateTargeting(?User $user, object $featureFlag): bool
    {
        // فقط super_admin و owner می‌توانند
        if (!$user || !in_array($user->role, ['admin', 'super_admin'])) {
            return false;
        }

        if ($user->role === 'admin') {
            return (int)$featureFlag->owner_user_id === (int)$user->id;
        }

        return true;
    }

    /**
     * آیا کاربر می‌تواند config values تغییر دهد؟
     */
    public function updateConfig(?User $user, object $featureFlag): bool
    {
        return $this->update($user, $featureFlag);
    }

    /**
     * آیا کاربر می‌تواند percentage rollout تغییر دهد؟
     */
    public function updateRollout(?User $user, object $featureFlag): bool
    {
        return $this->update($user, $featureFlag);
    }

    /**
     * آیا کاربر می‌تواند feature flag را enable/disable کند؟
     */
    public function toggle(?User $user, object $featureFlag): bool
    {
        return $this->update($user, $featureFlag);
    }

    /**
     * آیا کاربر می‌تواند تاریخچه تغییرات را ببیند؟
     */
    public function viewHistory(?User $user): bool
    {
        return $user && in_array($user->role, ['admin', 'super_admin']);
    }

    /**
     * آیا کاربر می‌تواند A/B tests را مدیریت کند؟
     */
    public function manageABTests(?User $user, object $featureFlag): bool
    {
        return $user && $user->role === 'super_admin';
    }

    /**
     * آیا کاربر می‌تواند Policies را مدیریت کند؟
     */
    public function managePolicies(?User $user, object $featureFlag): bool
    {
        return $user && $user->role === 'super_admin';
    }
}
