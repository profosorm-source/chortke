<?php

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * AccountDeletionLog Model
 */
class AccountDeletionLog extends Model
{
    protected static $table = 'account_deletion_logs';
    protected $fillable = [
        'user_id',
        'status',
        'requested_at',
        'expires_at',
        'deleted_at',
        'reason',
        'deleted_by'
    ];
    protected $timestamps = false;

    /**
     * دریافت درخواست‌های حذف
     */
    public function getUserDeletionRequest(int $userId): ?array
    {
        return $this->db->queryOne(
            "SELECT * FROM {$this->table} WHERE user_id = ? AND status IN ('requested', 'cancelled') ORDER BY requested_at DESC LIMIT 1",
            [$userId]
        );
    }

    /**
     * دریافت درخواست‌های حذف منقضی‌شده
     */
    public function getExpiredDeletionRequests(): array
    {
        return $this->db->query(
            "SELECT * FROM {$this->table} WHERE status = 'requested' AND expires_at < NOW()"
        ) ?: [];
    }

    /**
     * دریافت درخواست‌های حذف معلق
     */
    public function getPendingDeletions(): array
    {
        return $this->db->query(
            "SELECT d.*, u.username, u.email FROM {$this->table} d
             JOIN users u ON d.user_id = u.id
             WHERE d.status = 'requested'
             ORDER BY d.expires_at ASC"
        ) ?: [];
    }

    /**
     * دریافت حساب‌های حذف‌شده
     */
    public function getDeletedAccounts(int $limit = 100, int $offset = 0): array
    {
        return $this->db->query(
            "SELECT d.*, u.username, u.email, u.deleted_at AS user_deleted_at FROM {$this->table} d
             LEFT JOIN users u ON d.user_id = u.id
             WHERE d.status = 'deleted'
             ORDER BY d.deleted_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        ) ?: [];
    }

    /**
     * ایجاد درخواست حذف جدید
     */
    public function createDeletionRequest(int $userId, ?string $reason = null): int
    {
        return $this->db->query(
            "INSERT INTO {$this->table} (user_id, status, requested_at, expires_at, reason)
             VALUES (?, 'requested', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), ?)",
            [$userId, $reason]
        );
    }

    /**
     * لغو درخواست حذف
     */
    public function cancelDeletionRequest(int $userId): bool
    {
        return $this->db->query(
            "UPDATE {$this->table} SET status = 'cancelled' WHERE user_id = ? AND status = 'requested'",
            [$userId]
        ) !== false;
    }

    /**
     * ثبت حذف حساب
     */
    public function recordDeletion(int $userId, ?int $deletedBy = null, ?string $reason = null): bool
    {
        return $this->db->query(
            "UPDATE {$this->table} SET status = 'deleted', deleted_at = NOW(), deleted_by = ?, reason = ?
             WHERE user_id = ? AND status = 'requested'",
            [$deletedBy, $reason, $userId]
        ) !== false;
    }

    /**
     * دریافت تاریخچه حذف‌ها برای ادمین
     */
    public function getDeletionHistory(int $limit = 50, int $offset = 0): array
    {
        return $this->db->query(
            "SELECT d.*, u.username FROM {$this->table} d
             LEFT JOIN users u ON d.deleted_by = u.id
             ORDER BY d.requested_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        ) ?: [];
    }
}
