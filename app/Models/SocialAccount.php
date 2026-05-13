<?php
// app/Models/SocialAccount.php

namespace App\Models;
use Core\Model;

use Core\Database;

class SocialAccount extends Model {
    
    public int $id;
    public int $user_id;
    public string $platform;
    public string $username;
    public string $profile_url;
    public int $follower_count;
    public int $following_count;
    public int $post_count;
    public float $engagement_rate;
    public int $account_age_months;
    public string $status;
    public ?string $rejection_reason;
    public ?string $rejection_history;
    public ?int $verified_by;
    public ?string $verified_at;
    public bool $is_active;
    public string $created_at;
    public ?string $updated_at;
    public ?string $deleted_at;
/**
     * پیدا کردن بر اساس ID
     */
    public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_social_accounts 
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * پیدا کردن بر اساس user و platform
     */
    public function findByUserAndPlatform(int $userId, string $platform): ?self
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_social_accounts 
            WHERE user_id = ? AND platform = ? AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$userId, $platform]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * لیست حساب‌های یک کاربر
     */
    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_social_accounts 
            WHERE user_id = ? AND deleted_at IS NULL
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * حساب‌های تایید‌شده کاربر
     */
    public function getVerifiedByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM user_social_accounts 
            WHERE user_id = ? AND status = 'verified' AND is_active = 1 AND deleted_at IS NULL
            ORDER BY platform ASC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * لیست در انتظار تایید (Admin)
     */
    public function getPending(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT sa.*, u.full_name as user_name, u.email as user_email
            FROM user_social_accounts sa
            JOIN users u ON u.id = sa.user_id
            WHERE sa.status = 'pending' AND sa.deleted_at IS NULL
            ORDER BY sa.created_at ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    
    /**
     * لیست همه (Admin) با فیلتر
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['sa.deleted_at IS NULL'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'sa.status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['platform'])) {
            $where[] = 'sa.platform = ?';
            $params[] = $filters['platform'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(sa.username LIKE ? OR u.full_name LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        
        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare("
            SELECT sa.*, u.full_name as user_name, u.email as user_email
            FROM user_social_accounts sa
            JOIN users u ON u.id = sa.user_id
            WHERE {$whereStr}
            ORDER BY sa.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    
    /**
     * تعداد کل (برای صفحه‌بندی)
     */
    public function countAll(array $filters = []): int
    {
        $where = ['sa.deleted_at IS NULL'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'sa.status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['platform'])) {
            $where[] = 'sa.platform = ?';
            $params[] = $filters['platform'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(sa.username LIKE ? OR u.full_name LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_social_accounts sa
            JOIN users u ON u.id = sa.user_id
            WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * ایجاد حساب جدید
     */
    public function create(array $data): ?self
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_social_accounts 
            (user_id, platform, username, profile_url, follower_count, following_count, 
             post_count, engagement_rate, account_age_months, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $result = $stmt->execute([
            $data['user_id'],
            $data['platform'],
            $data['username'],
            $data['profile_url'],
            $data['follower_count'] ?? 0,
            $data['following_count'] ?? 0,
            $data['post_count'] ?? 0,
            $data['engagement_rate'] ?? 0,
            $data['account_age_months'] ?? 0,
        ]);
        
        if ($result) {
            return $this->find((int) $this->db->lastInsertId());
        }
        
        return null;
    }
    
    /**
     * بروزرسانی
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        
        $allowed = [
            'username', 'profile_url', 'follower_count', 'following_count',
            'post_count', 'engagement_rate', 'account_age_months',
            'status', 'rejection_reason', 'rejection_history',
            'verified_by', 'verified_at', 'is_active'
        ];
        
        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $id;
        
        $fieldStr = \implode(', ', $fields);
        $stmt = $this->db->prepare("
            UPDATE user_social_accounts SET {$fieldStr} WHERE id = ?
        ");
        
        return $stmt->execute($params);
    }
    
    /**
     * Soft Delete
     */
    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE user_social_accounts 
            SET deleted_at = NOW(), updated_at = NOW() 
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }
    
    /**
     * بررسی وجود حساب تکراری
     */
    public function existsByPlatformAndUsername(string $platform, string $username, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM user_social_accounts 
                WHERE platform = ? AND username = ? AND deleted_at IS NULL";
        $params = [$platform, $username];
        
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }
    
    /**
     * نام فارسی پلتفرم
     */
    public function platformLabel(string $platform): string
    {
        $labels = [
            'instagram' => 'اینستاگرام',
            'youtube'   => 'یوتیوب',
            'telegram'  => 'تلگرام',
            'tiktok'    => 'تیک‌تاک',
            'twitter'   => 'توییتر (X)',
        ];
        return $labels[$platform] ?? $platform;
    }
    
    /**
     * نام فارسی وضعیت
     */
    public function statusLabel(string $status): string
    {
        $labels = [
            'pending'  => 'در انتظار بررسی',
            'verified' => 'تایید شده',
            'rejected' => 'رد شده',
        ];
        return $labels[$status] ?? $status;
    }
    
    /**
     * کلاس CSS وضعیت
     */
    public function statusBadge(string $status): string
    {
        $badges = [
            'pending'  => 'warning',
            'verified' => 'success',
            'rejected' => 'danger',
        ];
        return $badges[$status] ?? 'secondary';
    }
    
    /**
     * تبدیل ردیف به شیء
     */
    private function hydrate(array $row): self
    {
        $obj = new self();
        
        $obj->id = (int) $row['id'];
        $obj->user_id = (int) $row['user_id'];
        $obj->platform = $row['platform'];
        $obj->username = $row['username'];
        $obj->profile_url = $row['profile_url'];
        $obj->follower_count = (int) $row['follower_count'];
        $obj->following_count = (int) $row['following_count'];
        $obj->post_count = (int) $row['post_count'];
        $obj->engagement_rate = (float) $row['engagement_rate'];
        $obj->account_age_months = (int) $row['account_age_months'];
        $obj->status = $row['status'];
        $obj->rejection_reason = $row['rejection_reason'] ?? null;
        $obj->rejection_history = $row['rejection_history'] ?? null;
        $obj->verified_by = isset($row['verified_by']) ? (int) $row['verified_by'] : null;
        $obj->verified_at = $row['verified_at'] ?? null;
        $obj->is_active = (bool) $row['is_active'];
        $obj->created_at = $row['created_at'];
        $obj->updated_at = $row['updated_at'] ?? null;
        $obj->deleted_at = $row['deleted_at'] ?? null;
        
        return $obj;
    }
}