<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Models\User;
use App\Models\Role;
use Core\Database;
use App\Services\AuditTrail;

use App\Contracts\LoggerInterface;
/**
 * PolicyService — سرویس اشتراکی مدیریت سطوح دسترسی (RBAC)
 *
 * این سرویس جایگزین App\Services\PolicyService شده است.
 * مسئول تمامی بررسی‌های احراز هویت و دسترسی‌های نقش‌محور می‌باشد.
 */
class PolicyService
{
    private array $permissionCache = [];

    private \App\Contracts\LoggerInterface $logger;
    private User $userModel;
    private Role $roleModel;
    private AuditTrail $auditTrail;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        User $userModel,
        Role $roleModel,
        AuditTrail $auditTrail
    ) {        $this->logger = $logger;
        $this->userModel = $userModel;
        $this->roleModel = $roleModel;
        $this->auditTrail = $auditTrail;

        
    }

    public function can(string $action, User $user, $resource = null): bool
    {
        if ($this->isSuperAdmin($user)) return true;

        if ($resource) return $this->canOnResource($action, $user, $resource);

        return $this->hasPermission($user, $action);
    }

    public function authorize(string $action, User $user, $resource = null): void
    {
        if (!$this->can($action, $user, $resource)) {
            $this->auditTrail->log('authorization_denied', "User {$user->id} denied for action: $action", [
                'action' => $action,
                'user_id' => $user->id,
            ]);
            throw new \Exception("دسترسی غیرمجاز به عملیات: $action");
        }
    }

    private function canOnResource(string $action, User $user, $resource): bool
    {
        if (isset($resource->user_id) && $resource->user_id === $user->id) return true;

        $parts = explode('.', $action);
        if (count($parts) >= 2 && $parts[0] === 'admin' && $this->isAdmin($user)) {
            return true;
        }

        return $this->hasPermission($user, $action);
    }

    private function cacheSet(string $key, bool $value): void
    {
        if (count($this->permissionCache) >= 1000) {
            // Clear entire cache to prevent memory creep in long-lived processes
            $this->permissionCache = [];
        }
        $this->permissionCache[$key] = $value;
    }

    private function hasPermission(User $user, string $action): bool
    {
        $cacheKey = "user_{$user->id}_action_{$action}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        // Architectural Decoupling: Delegating lookups directly into model logic.
        $result = $this->userModel->hasPermission($user->id, $action);

        $this->cacheSet($cacheKey, $result);
        return $result;
    }

    public function isAdmin(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin']);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $user->role === 'super_admin';
    }

    public function isModerator(User $user): bool
    {
        return in_array($user->role, ['admin', 'super_admin', 'moderator']);
    }

    /**
     * بررسی admin بودن با ID — برای استفاده در BaseController
     */
    public function isAdminById(int $userId): bool
    {
        $user = $this->userModel->findById($userId);
        return $user && in_array($user->role, ['admin', 'super_admin']);
    }

    /**
     * بررسی permission با ID — برای استفاده در BaseController
     */
    public function authorizeById(string $action, int $userId): bool
    {
        $cacheKey = "uid_{$userId}_{$action}";
        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $result = $this->userModel->hasPermission($userId, $action);

        $this->cacheSet($cacheKey, $result);
        return $result;
    }

    public function getPermissions(User $user): array
    {
        return $this->userModel->getUserPermissions($user->id);
    }

    public function grantRole(User $user, string $roleSlug, ?int $grantedBy = null): bool
    {
        try {
            $role = $this->roleModel->findBySlug($roleSlug);
            if (!$role) throw new \Exception("Role '$roleSlug' یافت نشد");

            $this->userModel->assignRole($user->id, $role->id, $grantedBy);

            $this->auditTrail->log('role_granted', "Granted role $roleSlug to user {$user->id}", ['role' => $roleSlug]);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('grant_role_error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function revokeRole(User $user, string $roleSlug, ?int $revokedBy = null): bool
    {
        try {
            $role = $this->roleModel->findBySlug($roleSlug);
            if (!$role) throw new \Exception("Role '$roleSlug' یافت نشد");

            $this->userModel->removeRole($user->id, $role->id);
            $this->auditTrail->log('role_revoked', "Revoked role $roleSlug from user {$user->id}");
            return true;
        } catch (\Exception $e) {
            $this->logger->error('revoke_role_error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function clearCache(int $userId): void
    {
        $this->permissionCache = array_filter(
            $this->permissionCache,
            fn($key) => !str_starts_with($key, "user_{$userId}_") && !str_starts_with($key, "uid_{$userId}_"),
            ARRAY_FILTER_USE_KEY
        );
    }
}

