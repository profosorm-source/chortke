<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Interaction Model - مدل اشتراکی مدیریت تعاملات کاربری
 * 
 * شامل علاقه‌مندی‌ها (Favorites)، و گزارش تخلفات (Reports) برای موجودیت‌های مختلف
 */
class InteractionModel extends Model
{
    // ==========================================
    // Favorites (TaskFavorite)
    // ==========================================

    /**
     * بررسی علاقه‌مندی
     */
    public function isFavorite(int $taskId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_favorites
            WHERE task_id = ? AND user_id = ?
        ");
        $stmt->execute([$taskId, $userId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * دریافت لیست علاقه‌مندی‌های کاربر
     */
    public function getUserFavorites(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT ct.*, 
                   u.full_name AS creator_name,
                   (ct.total_quantity - ct.completed_count - ct.pending_count) AS remaining_count
            FROM task_favorites tf
            INNER JOIN custom_tasks ct ON ct.id = tf.task_id
            LEFT JOIN users u ON u.id = ct.creator_id
            WHERE tf.user_id = ? AND ct.deleted_at IS NULL
            ORDER BY tf.created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد علاقه‌مندی‌های کاربر
     */
    public function countUserFavorites(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM task_favorites tf
            INNER JOIN custom_tasks ct ON ct.id = tf.task_id
            WHERE tf.user_id = ? AND ct.deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    // ==========================================
    // Task Reports (TaskReport)
    // ==========================================

    /**
     * ایجاد گزارش جدید تسک
     */
    public function createTaskReport(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO task_reports
            (task_id, reporter_id, reason, description, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
        ");

        $result = $stmt->execute([
            $data['task_id'],
            $data['reporter_id'],
            $data['reason'],
            $data['description'],
        ]);

        if (!$result) {
            return null;
        }

        return $this->findTaskReport((int) $this->db->lastInsertId());
    }

    /**
     * یافتن یک گزارش تسک
     */
    public function findTaskReport(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT r.*,
                   ct.title AS task_title,
                   u.full_name AS reporter_name,
                   a.full_name AS admin_name
            FROM task_reports r
            LEFT JOIN custom_tasks ct ON ct.id = r.task_id
            LEFT JOIN users u ON u.id = r.reporter_id
            LEFT JOIN users a ON a.id = r.admin_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    /**
     * بررسی گزارش تکراری
     */
    public function hasPendingTaskReport(int $taskId, int $reporterId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_reports
            WHERE task_id = ? AND reporter_id = ? 
            AND status = 'pending'
        ");
        $stmt->execute([$taskId, $reporterId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * به‌روزرسانی وضعیت گزارش تسک
     */
    public function updateTaskReport(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = ['status', 'admin_id', 'admin_note', 'resolved_at'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $values[] = $id;

        $stmt = $this->db->prepare(
            "UPDATE task_reports SET " . implode(', ', $fields) . " WHERE id = ?"
        );

        return $stmt->execute($values);
    }

    public function adminListTaskReports(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['reason'])) {
            $where[] = "r.reason = ?";
            $params[] = $filters['reason'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT r.*,
                   ct.title AS task_title,
                   u.full_name AS reporter_name
            FROM task_reports r
            LEFT JOIN custom_tasks ct ON ct.id = r.task_id
            LEFT JOIN users u ON u.id = r.reporter_id
            WHERE {$whereStr}
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCountTaskReports(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['reason'])) {
            $where[] = "r.reason = ?";
            $params[] = $filters['reason'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_reports r WHERE {$whereStr}
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function getTaskReportCount(int $taskId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_reports
            WHERE task_id = ?
        ");
        $stmt->execute([$taskId]);
        return (int) $stmt->fetchColumn();
    }

    public function taskReportReasonLabels(): array
    {
        return [
            'spam' => 'اسپم',
            'fraud' => 'تقلب',
            'inappropriate' => 'نامناسب',
            'misleading' => 'گمراه‌کننده',
            'other' => 'سایر',
        ];
    }

    public function taskReportStatusLabels(): array
    {
        return [
            'pending' => 'در انتظار',
            'reviewed' => 'بررسی شده',
            'resolved' => 'حل شده',
            'rejected' => 'رد شده',
        ];
    }

    // ==========================================
    // Message Reports (MessageReport)
    // ==========================================

    public function findMessageReportsPaginated(
        int $limit,
        int $offset,
        ?string $status = null
    ): array {
        $where = '1=1';
        $params = [];

        if ($status && $status !== 'all') {
            $where .= ' AND mr.status = ?';
            $params[] = $status;
        }

        $sql = "SELECT 
                mr.id, mr.message_id, mr.reason, mr.status, mr.created_at,
                dm.message, dm.sender_id, dm.recipient_id,
                u.name as reporter_name, u.email as reporter_email
             FROM message_reports mr
             JOIN direct_messages dm ON mr.message_id = dm.id
             JOIN users u ON mr.reporter_id = u.id
             WHERE {$where}
             ORDER BY mr.created_at DESC
             LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }

    public function countMessageReports(?string $status = null): int
    {
        $where = '1=1';
        $params = [];

        if ($status && $status !== 'all') {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $count = $this->db->query(
            "SELECT COUNT(*) as count FROM message_reports WHERE {$where}",
            $params
        )->fetch();

        return (int) ($count['count'] ?? 0);
    }

    public function findMessageReportById(int $id): ?array
    {
        $report = $this->db->query(
            "SELECT mr.*, dm.* 
             FROM message_reports mr
             JOIN direct_messages dm ON mr.message_id = dm.id
             WHERE mr.id = ?",
            [$id]
        )->fetch();

        return $report ?: null;
    }
}
