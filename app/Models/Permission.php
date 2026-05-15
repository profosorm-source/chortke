<?php

namespace App\Models;

use Core\Model;

class Permission extends Model
{
    protected static string $table = 'permissions';
    
    private array $userPermissionsCache = [];
    private array $isSuperAdminCache = [];

    /**
     * یافتن دسترسی با ID
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM permissions WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result ?: null;
    }

    /**
     * یافتن دسترسی با slug
     */
    public function findBySlug(string $slug): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM permissions WHERE slug = ?");
        $stmt->execute([$slug]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result ?: null;
    }

    /**
     * دریافت تمام دسترسی‌ها
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->db->prepare("SELECT * FROM permissions ORDER BY group_name ASC, name ASC");
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت دسترسی‌ها گروه‌بندی شده
     */
    public function allGrouped(): array
    {
        $permissions = $this->all();
        $grouped = [];

        foreach ($permissions as $perm) {
            $group = (string)($perm->group_name ?? 'other');
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $perm;
        }

        return $grouped;
    }

    /**
     * نام فارسی گروه‌ها
     */
    public function groupLabels(): array
    {
        return [
            'users'       => 'مدیریت کاربران',
            'finance'     => 'مدیریت مالی',
            'kyc'         => 'احراز هویت',
            'tasks'       => 'تسک‌ها و تبلیغات',
            'tickets'     => 'پشتیبانی',
            'investments' => 'سرمایه‌گذاری',
            'lottery'     => 'قرعه‌کشی',
            'stories'     => 'سفارش استوری',
            'content'     => 'محتوا و استعداد',
            'settings'    => 'تنظیمات سیستم',
            'roles'       => 'نقش‌ها و دسترسی‌ها',
            'logs'        => 'لاگ‌ها',
            'reports'     => 'گزارش‌ها',
            'banners'     => 'بنر و تبلیغات',
            'coupons'     => 'کوپن تخفیف',
            'referrals'   => 'سیستم معرفی',
            'system'      => 'سیستم',
            'bugs'        => 'گزارش باگ',
            'other'       => 'سایر',
        ];
    }

    /**
     * بررسی دسترسی کاربر با ککش داینامیک درون‌حافظه‌ای
     */
    public function userHasPermission(int $userId, string $permSlug): bool
    {
        if ($this->isSuperAdmin($userId)) {
            return true;
        }

        if (!isset($this->userPermissionsCache[$userId])) {
            $this->userPermissionsCache[$userId] = $this->getUserPermissions($userId);
        }

        return \in_array($permSlug, $this->userPermissionsCache[$userId], true);
    }

    /**
     * بررسی آیا کاربر super_admin است با ککش درون‌حافظه‌ای
     */
    public function isSuperAdmin(int $userId): bool
    {
        if (isset($this->isSuperAdminCache[$userId])) {
            return $this->isSuperAdminCache[$userId];
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND r.slug = 'super_admin' AND u.deleted_at IS NULL
        ");

        $stmt->execute([$userId]);
        $isSuper = (int)$stmt->fetchColumn() > 0;

        $this->isSuperAdminCache[$userId] = $isSuper;
        return $isSuper;
    }

    /**
     * دریافت تمام دسترسی‌های کاربر (slug ها)
     */
    public function getUserPermissions(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.slug 
            FROM users u
            INNER JOIN role_permissions rp ON rp.role_id = u.role_id
            INNER JOIN permissions p ON p.id = rp.permission_id
            WHERE u.id = ? AND u.deleted_at IS NULL
        ");

        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}