<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

class Appeal
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findAppeal(int $appealId): ?array
    {
        $appeal = $this->db->query(
            "SELECT a.*, u.username, u.email, COUNT(at.id) as attachment_count
             FROM appeals a
             JOIN users u ON a.user_id = u.id
             LEFT JOIN appeal_attachments at ON a.id = at.appeal_id
             WHERE a.id = ?
             GROUP BY a.id",
            [$appealId]
        )->fetch();

        return $appeal ?: null;
    }

    public function getAttachments(int $appealId): array
    {
        return $this->db->query(
            "SELECT * FROM appeal_attachments
             WHERE appeal_id = ? ORDER BY uploaded_at ASC",
            [$appealId]
        )->fetchAll();
    }

    public function getResponses(int $appealId): array
    {
        return $this->db->query(
            "SELECT ar.*, u.username as admin_username
             FROM appeal_responses ar
             JOIN users u ON ar.admin_id = u.id
             WHERE ar.appeal_id = ? ORDER BY ar.created_at ASC",
            [$appealId]
        )->fetchAll();
    }

    public function findAppealForUser(int $appealId, int $userId): ?array
    {
        $appeal = $this->db->query(
            "SELECT a.*, u.username as admin_username
             FROM appeals a
             LEFT JOIN users u ON a.admin_id = u.id
             WHERE a.id = ? AND a.user_id = ?",
            [$appealId, $userId]
        )->fetch();

        return $appeal ?: null;
    }

    /**
     * ارسال اعتراض جدید
     */
    public function create(
        int $userId,
        string $appealType,
        string $title,
        string $description,
        ?int $referenceId = null,
        ?string $referenceType = null,
        string $priority = 'medium'
    ): ?int {
        $result = $this->db->query(
            "INSERT INTO appeals (user_id, appeal_type, reference_id, reference_type, title, description, priority, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId,
                $appealType,
                $referenceId,
                $referenceType,
                $title,
                $description,
                $priority
            ]
        );

        return $result ? $this->db->lastInsertId() : null;
    }

    /**
     * شمارنده اعتراضات کاربر
     */
    public function getUserAppealCount(int $userId): object
    {
        return $this->db->query(
            "SELECT appeal_count, last_appeal_at, appeal_banned_until FROM users WHERE id = ?",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ) ?? (object)['appeal_count' => 0, 'last_appeal_at' => null, 'appeal_banned_until' => null];
    }

    /**
     * شمارنده اعتراضات روزانه
     */
    public function getDailyAppealCount(int $userId, string $date): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM appeals WHERE user_id = ? AND DATE(created_at) = ?",
            [$userId, $date]
        )->fetch();

        return (int)($result['count'] ?? 0);
    }

    /**
     * شمارنده اعتراضات هفتگی
     */
    public function getWeeklyAppealCount(int $userId, string $weekAgo): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM appeals WHERE user_id = ? AND created_at >= ?",
            [$userId, $weekAgo]
        )->fetch();

        return (int)($result['count'] ?? 0);
    }

    /**
     * بروزرسانی شمارنده کاربر
     */
    public function incrementUserAppealCount(int $userId): bool
    {
        return (bool)$this->db->query(
            "UPDATE users SET appeal_count = appeal_count + 1, last_appeal_at = NOW() WHERE id = ?",
            [$userId]
        );
    }

    /**
     * اضافه کردن پیوست
     */
    public function addAttachment(int $appealId, int $userId, array $attachment): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO appeal_attachments 
             (appeal_id, user_id, filename, original_name, file_path, file_size, mime_type, uploaded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $appealId,
                $userId,
                $attachment['filename'],
                $attachment['original_name'],
                $attachment['file_path'],
                $attachment['file_size'],
                $attachment['mime_type']
            ]
        );
    }

    /**
     * دریافت اعتراضات کاربر
     */
    public function getUserAppeals(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit = \max(1, $limit);
        $offset = \max(0, $offset);

        $stmt = $this->db->prepare(
            "SELECT a.*,
                    COALESCE(at.cnt, 0) as attachment_count,
                    COALESCE(ar.cnt, 0) as response_count
             FROM appeals a
             LEFT JOIN (
                 SELECT appeal_id, COUNT(*) as cnt FROM appeal_attachments GROUP BY appeal_id
             ) at ON a.id = at.appeal_id
             LEFT JOIN (
                 SELECT appeal_id, COUNT(*) as cnt FROM appeal_responses GROUP BY appeal_id
             ) ar ON a.id = ar.appeal_id
             WHERE a.user_id = :user_id
             ORDER BY a.created_at DESC
             LIMIT :limit OFFSET :offset"
        );

        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?? [];
    }

    public function getAppealsByStatus(
        ?string $status = null,
        ?string $priority = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $limit = \max(1, $limit);
        $offset = \max(0, $offset);

        $conditions = [];
        $params = [];

        if ($status) {
            $conditions[] = "a.status = :status";
            $params['status'] = $status;
        }

        if ($priority) {
            $conditions[] = "a.priority = :priority";
            $params['priority'] = $priority;
        }

        $whereClause = !empty($conditions) ? "WHERE " . \implode(" AND ", $conditions) : "";

        $sql = "SELECT a.*, u.username, u.email,
                       COALESCE(at.cnt, 0) as attachment_count
                FROM appeals a
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN (
                    SELECT appeal_id, COUNT(*) as cnt FROM appeal_attachments GROUP BY appeal_id
                ) at ON a.id = at.appeal_id
                {$whereClause}
                ORDER BY 
                   CASE a.priority 
                       WHEN 'urgent' THEN 1 
                       WHEN 'high' THEN 2 
                       WHEN 'medium' THEN 3 
                       ELSE 4 
                   END,
                   a.created_at ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?? [];
    }

    /**
     * بررسی و تصمیم خودکار
     */
    public function getAppealForAutoDecision(int $appealId): ?object
    {
        return $this->db->query(
            "SELECT a.*, u.appeal_count, u.created_at as user_created_at
             FROM appeals a
             JOIN users u ON a.user_id = u.id
             WHERE a.id = ?",
            [$appealId]
        )->fetch(\PDO::FETCH_OBJ);
    }

    /**
     * آپدیت تصمیم خودکار
     */
    public function updateAutoDecision(int $appealId, string $status, string $decision): bool
    {
        return (bool)$this->db->query(
            "UPDATE appeals SET
             status = ?, decision = ?, decision_at = NOW(), auto_decision = 1
             WHERE id = ?",
            [$status, $decision, $appealId]
        );
    }
}
