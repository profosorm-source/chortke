<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class Role extends Model {
    protected static string $table = 'roles';

    /**
     * یافتن نقش با ID
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result ?: null;
    }

    /**
     * یافتن نقش با slug
     */
    public function findBySlug(string $slug): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE slug = ? AND deleted_at IS NULL");
        $stmt->execute([$slug]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result ?: null;
    }

    /**
     * دریافت تمام نقش‌ها (سازگار با Core\Model)
     */
    public function all($filters = [], $limit = 100, $offset = 0): array
    {
        return $this->allRoles(true);
    }

    /**
     * دریافت تمام نقش‌ها با فیلتر فعال/غیرفعال
     */
    public function allRoles(bool $onlyActive = true): array
    {
        $query = $this->db->table('roles as r')
            ->select('r.*')
            ->selectRaw('COUNT(u.id) as user_count')
            ->leftJoin('users as u', 'u.role_id', '=', 'r.id')
            ->whereNull('r.deleted_at')
            ->where(function ($q) {
                $q->whereNull('u.deleted_at')
                  ->orWhereNull('u.id');
            });

        if ($onlyActive) {
            $query->where('r.is_active', '=', 1);
        }

        return $query->groupBy('r.id')
            ->orderBy('r.id', 'ASC')
            ->get();
    }

    /**
     * ایجاد نقش جدید
     */
    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $ok = $stmt->execute([
            (string)($data['name'] ?? ''),
            (string)($data['slug'] ?? ''),
            $data['description'] ?? null,
            0, // 🚀 BUG FIX [L-02]: Force is_system = 0. System roles should only be created via migrations.
            (int)($data['is_active'] ?? 1),
        ]);

        if (!$ok) {
            return null;
        }

        // نیازمند Database::lastInsertId()
        $newId = (int)$this->db->lastInsertId();
        return $newId > 0 ? $this->find($newId) : null;
    }

    /**
     * بروزرسانی نقش
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = ['name', 'description', 'is_active'];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        // updated_at هم آپدیت شود
        $fields[] = "updated_at = NOW()";

        $values[] = $id;

        $stmt = $this->db->prepare("
            UPDATE roles SET " . \implode(', ', $fields) . "
            WHERE id = ? AND deleted_at IS NULL
        ");

        return $stmt->execute($values);
    }

    /**
     * حذف نرم نقش (فقط غیر سیستمی)
     */
    public function delete(int $id): bool
    {
        $role = $this->find($id);
        if (!$role || (int)$role->is_system === 1) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE roles SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND is_system = 0
        ");

        return $stmt->execute([$id]);
    }

    /**
     * دریافت دسترسی‌های یک نقش
     */
    public function getPermissions(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.* FROM permissions p
            INNER JOIN role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role_id = ?
            ORDER BY p.group_name ASC, p.name ASC
        ");
        $stmt->execute([$roleId]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت slug های دسترسی‌های یک نقش
     */
    public function getPermissionSlugs(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.slug FROM permissions p
            INNER JOIN role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * همگام‌سازی دسترسی‌های نقش
     * (حذف/درج روی pivot طبیعی است؛ چون role_permissions ستون deleted_at ندارد)
     */
    public function syncPermissions(int $roleId, array $permissionIds): bool
    {
        try {
            $this->db->beginTransaction();

            // حذف قبلی‌ها
            $this->db->table('role_permissions')
                ->where('role_id', '=', $roleId)
                ->delete();

            // درج جدیدها
            $permissionIds = \array_values(\array_unique(\array_map('intval', $permissionIds)));

            if (!empty($permissionIds)) {
                // 🚀 BUG FIX [M-04]: Validate permission IDs existence
                $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
                $sql = "SELECT COUNT(*) FROM permissions WHERE id IN ($placeholders)";
                $count = (int)$this->db->fetchColumn($sql, $permissionIds);
                
                if ($count !== count($permissionIds)) {
                    throw new \InvalidArgumentException("One or more permission IDs are invalid.");
                }

                $inserts = [];
                foreach ($permissionIds as $permId) {
                    $inserts[] = [
                        'role_id' => $roleId,
                        'permission_id' => $permId
                    ];
                }
                $this->db->table('role_permissions')->insert($inserts);
            }

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            $this->db->rollBack();

            if (\function_exists('logger')) {
                logger()->error('role.sync_permissions.failed', [
                    'channel' => 'rbac',
                    'role_id' => $roleId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }

            return false;
        }
    }

    /**
     * تعداد کاربران هر نقش
     */
    public function getUserCount(int $roleId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE role_id = ? AND deleted_at IS NULL");
        $stmt->execute([$roleId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * بررسی وجود slug
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM roles WHERE slug = ? AND deleted_at IS NULL";
        $params = [$slug];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn() > 0;
    }
}