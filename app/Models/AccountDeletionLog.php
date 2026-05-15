<?php

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * AccountDeletionLog Model - Secured against SQL Injection and Race Conditions
 */
class AccountDeletionLog extends Model
{
    protected static string $table = 'account_deletion_logs';
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
        $row = $this->db->fetch(
            "SELECT * FROM `account_deletion_logs` WHERE user_id = ? AND status IN ('requested', 'cancelled') ORDER BY requested_at DESC LIMIT 1",
            [$userId]
        );
        return $row ? (array)$row : null;
    }

    /**
     * دریافت درخواست‌های حذف منقضی‌شده
     */
    public function getExpiredDeletionRequests(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `account_deletion_logs` WHERE status = 'requested' AND expires_at < NOW()"
        ) ?: [];
    }

    /**
     * دریافت درخواست‌های حذف معلق
     */
    public function getPendingDeletions(): array
    {
        return $this->db->fetchAll(
            "SELECT d.id, d.user_id, d.status, d.requested_at, d.expires_at, u.username, u.email FROM `account_deletion_logs` d
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
        return $this->db->fetchAll(
            "SELECT d.id, d.user_id, d.status, d.requested_at, d.deleted_at, d.deleted_by, u.username, u.email, u.deleted_at AS user_deleted_at FROM `account_deletion_logs` d
             LEFT JOIN users u ON d.user_id = u.id
             WHERE d.status = 'deleted'
             ORDER BY d.deleted_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        ) ?: [];
    }

    /**
     * ایجاد درخواست حذف جدید با محافظ تراکنش و قفل ایمن جهت جلوگیری از Race Condition
     */
    public function createDeletionRequest(int $userId, ?string $reason = null): int
    {
        $this->db->beginTransaction();
        try {
            // قفل انحصاری جهت جلوگیری از ثبت درخواست تکراری همزمان
            $existing = $this->db->fetch(
                "SELECT id FROM `account_deletion_logs` WHERE user_id = ? AND status = 'requested' FOR UPDATE",
                [$userId]
            );

            if ($existing) {
                throw new \RuntimeException('Deletion request already exists');
            }

            $this->db->query(
                "INSERT INTO `account_deletion_logs` (user_id, status, requested_at, expires_at, reason)
                 VALUES (?, 'requested', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), ?)",
                [$userId, $reason]
            );

            $lastId = $this->db->lastInsertId();
            $this->db->commit();
            return $lastId;
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * لغو درخواست حذف
     */
    public function cancelDeletionRequest(int $userId): bool
    {
        return $this->db->execute(
            "UPDATE `account_deletion_logs` SET status = 'cancelled' WHERE user_id = ? AND status = 'requested'",
            [$userId]
        ) > 0;
    }

    /**
     * ثبت حذف حساب
     */
    public function recordDeletion(int $userId, ?int $deletedBy = null, ?string $reason = null): bool
    {
        return $this->db->execute(
            "UPDATE `account_deletion_logs` SET status = 'deleted', deleted_at = NOW(), deleted_by = ?, reason = ?
             WHERE user_id = ? AND status = 'requested'",
            [$deletedBy, $reason, $userId]
        ) > 0;
    }

    /**
     * دریافت تاریخچه حذف‌ها برای ادمین
     */
    public function getDeletionHistory(int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT d.*, u.username FROM `account_deletion_logs` d
             LEFT JOIN users u ON d.deleted_by = u.id
             ORDER BY d.requested_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        ) ?: [];
    }
}
