<?php
declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * MessageModerationModel
 */
class MessageModerationModel extends Model
{
    /**
     * دریافت پیام‌های یک کاربر برای بررسی تاریخچه (بدون اطلاعات حساس طرف مقابل)
     */
    public function getUserMessages(int $senderId, int $limit = 10): array
    {
        $sql = "SELECT id, message, created_at, is_encrypted 
                FROM direct_messages 
                WHERE sender_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";
        
        return $this->db->fetchAll($sql, [$senderId, $limit]);
    }

    /**
     * بروزرسانی وضعیت گزارش
     */
    public function updateReportStatus(int $reportId, string $status, int $adminId): bool
    {
        $sql = "UPDATE message_reports 
                SET status = ?, admin_id = ?, updated_at = NOW() 
                WHERE id = ?";
        
        return (bool)$this->db->query($sql, [$status, $adminId, $reportId]);
    }

    /**
     * دریافت کاربران مسدود شده
     */
    public function getBlockedUsers(int $limit, int $offset): array
    {
        $sql = "SELECT ub.*, u.name as blocked_name, u.email as blocked_email, a.name as admin_name
                FROM user_blocks ub
                JOIN users u ON ub.user_id = u.id
                JOIN users a ON ub.blocked_by = a.id
                ORDER BY ub.blocked_at DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
