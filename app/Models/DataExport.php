<?php

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * DataExport Model
 */
class DataExport extends Model
{
    protected static $table = 'data_exports';
    protected $fillable = [
        'user_id',
        'format',
        'file_path',
        'status',
        'error_message',
        'requested_at',
        'completed_at',
        'expires_at'
    ];
    protected $timestamps = false;

    /**
     * دریافت درخواست‌های صادرکردن کاربر
     */
    public function getUserExports(int $userId, int $limit = 20): array
    {
        return $this->db->query(
            "SELECT * FROM " . static::$table . " WHERE user_id = ? ORDER BY requested_at DESC LIMIT ?",
            [$userId, $limit]
        ) ?: [];
    }

    /**
     * دریافت درخواست‌های درحال‌انتظار
     */
    public function getPendingExports(): array
    {
        return $this->db->query(
            "SELECT * FROM " . static::$table . " WHERE status IN ('pending', 'processing') ORDER BY requested_at ASC"
        ) ?: [];
    }

    /**
     * دریافت درخواست‌های منقضی برای حذف
     */
    public function getExpiredExports(): array
    {
        return $this->db->query(
            "SELECT * FROM " . static::$table . " WHERE status = 'completed' AND expires_at < NOW()"
        ) ?: [];
    }

    /**
     * ایجاد درخواست صادرکردن جدید
     */
    public function createExport(int $userId, string $format): int
    {
        return $this->db->query(
            "INSERT INTO " . static::$table . " (user_id, format, status) VALUES (?, ?, 'pending')",
            [$userId, $format]
        );
    }

    /**
     * بروزرسانی وضعیت درخواست
     */
    public function updateStatus(int $id, string $status, ?string $filePath = null, ?string $error = null): bool
    {
        $query = "UPDATE " . static::$table . " SET status = ?, updated_at = NOW()";
        $params = [$status];

        if ($filePath) {
            $query .= ", file_path = ?";
            $params[] = $filePath;
        }

        if ($error) {
            $query .= ", error_message = ?";
            $params[] = $error;
        }

        if ($status === 'completed') {
            $query .= ", completed_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)";
        }

        $query .= " WHERE id = ?";
        $params[] = $id;

        return $this->db->query($query, $params) !== false;
    }

    /**
     * حذف مسیر فایل صادرشده پس از انقضا
     */
    public function clearFilePath(int $id): bool
    {
        return $this->db->query(
            "UPDATE " . static::$table . " SET file_path = NULL, updated_at = NOW() WHERE id = ?",
            [$id]
        ) !== false;
    }
}
