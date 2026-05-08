<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class Investment extends Model {
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_SUSPENDED = 'suspended';

    public const WITHDRAWAL_COOLDOWN_DAYS = 7;
    public const DEPOSIT_LOCK_DAYS = 7;
/**
     * ایجاد سرمایه‌گذاری جدید
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        // مقدارهای پیش‌فرض
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $data['deleted_at'] = $data['deleted_at'] ?? 0;

        if (!isset($data['status'])) {
            $data['status'] = self::STATUS_ACTIVE;
        }

        // ساخت INSERT داینامیک
        $columns = \array_keys($data);
        $values  = \array_values($data);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `investments` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM investments WHERE id = ? AND deleted_at = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function findWithUser(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT i.*, u.full_name as user_name, u.email as user_email
             FROM investments i
             JOIN users u ON i.user_id = u.id
             WHERE i.id = ? AND i.deleted_at = 0
             LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    /**
     * سرمایه‌گذاری فعال کاربر (فقط یک پلن)
     */
    public function getActiveByUser(int $userId): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM investments
             WHERE user_id = ? AND status = ? AND deleted_at = 0
             ORDER BY created_at DESC LIMIT 1",
            [$userId, self::STATUS_ACTIVE]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function hasActiveInvestment(int $userId): bool
    {
        return $this->getActiveByUser($userId) !== null;
    }

    /**
     * تمام سرمایه‌گذاری‌های کاربر
     */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $stmt = $this->db->query(
            "SELECT * FROM investments
             WHERE user_id = ? AND deleted_at = 0
             ORDER BY created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$userId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total FROM investments WHERE user_id = ? AND deleted_at = 0",
            [$userId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0);
    }

    /**
     * تمام سرمایه‌گذاری‌ها (ادمین)
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT i.*, u.full_name as user_name, u.email as user_email
                FROM investments i
                JOIN users u ON i.user_id = u.id
                WHERE i.deleted_at = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND i.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $sql .= " ORDER BY i.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM investments i
                JOIN users u ON i.user_id = u.id
                WHERE i.deleted_at = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND i.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    /**
     * بروزرسانی سرمایه‌گذاری
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = \date('Y-m-d H:i:s');

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            $fields[] = "`{$k}` = ?";
            $values[] = $v;
        }

        $values[] = $id;

        $sql = "UPDATE investments SET " . \implode(', ', $fields) . " WHERE id = ? AND deleted_at = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    /**
     * بررسی قفل واریز (بعد از برداشت ۷ روز نمی‌تواند واریز کند)
     */
    public function isDepositLocked(int $userId): bool
    {
        $inv = $this->getActiveByUser($userId);
        if (!$inv || empty($inv->deposit_lock_until)) {
            return false;
        }

        return \strtotime((string)$inv->deposit_lock_until) > \time();
    }

    /**
     * بررسی اجازه برداشت (هر ۷ روز یکبار)
     */
    public function canWithdraw(int $userId): array
    {
        $inv = $this->getActiveByUser($userId);

        if (!$inv) {
            return [
                'allowed' => false,
                'reason'  => 'شما سرمایه‌گذاری فعال ندارید.',
            ];
        }

        // اگر قبلاً برداشت کرده:
        if (!empty($inv->last_withdrawal_date)) {
            $last = \strtotime((string)$inv->last_withdrawal_date);
            $nextAllowed = $last + (self::WITHDRAWAL_COOLDOWN_DAYS * 86400);

            if (\time() < $nextAllowed) {
                $remaining = (int)\ceil(($nextAllowed - \time()) / 86400);

                return [
                    'allowed'   => false,
                    'reason'    => "برداشت بعدی تا {$remaining} روز دیگر مجاز است.",
                    'next_date' => \date('Y-m-d H:i:s', $nextAllowed),
                ];
            }
        }

        // حداقل ۷ روز از شروع سرمایه‌گذاری
        $startDate = \strtotime((string)($inv->start_date ?? $inv->created_at ?? 'now'));
        $minDate = $startDate + (self::WITHDRAWAL_COOLDOWN_DAYS * 86400);

        if (\time() < $minDate) {
            $remaining = (int)\ceil(($minDate - \time()) / 86400);

            return [
                'allowed' => false,
                'reason'  => "برداشت سود پس از {$remaining} روز دیگر مجاز است.",
            ];
        }

        return ['allowed' => true];
    }

    /**
     * آمار کلی (ادمین)
     */
    public function getStats(): object
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                SUM(CASE WHEN status = 'frozen' THEN 1 ELSE 0 END) as frozen_count,
                COALESCE(SUM(CASE WHEN status = 'active' THEN amount ELSE 0 END), 0) as total_invested,
                COALESCE(SUM(CASE WHEN status = 'active' THEN current_balance ELSE 0 END), 0) as total_balance,
                COALESCE(SUM(total_profit), 0) as total_profit_all,
                COALESCE(SUM(total_loss), 0) as total_loss_all
            FROM investments WHERE deleted_at = 0"
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row ?: (object)[];
    }
}