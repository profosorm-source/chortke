<?php
/**
 * مدل ارسال محتوا
 * 
 * @package App\Models
 */

namespace App\Models;

use Core\Model;
use Core\Database;
use PDO;

class ContentSubmission extends Model
{
    // Status Constants
    public const STATUS_PENDING      = 'pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_PUBLISHED    = 'published';
    public const STATUS_SUSPENDED    = 'suspended';

    // Platform Constants
    public const PLATFORM_APARAT  = 'aparat';
    public const PLATFORM_YOUTUBE = 'youtube';
    public const PLATFORM_UPLOAD_CENTER = 'upload_center';

    public const ALLOWED_PLATFORMS = [
        self::PLATFORM_APARAT,
        self::PLATFORM_YOUTUBE,
        self::PLATFORM_UPLOAD_CENTER,
    ];

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PUBLISHED,
        self::STATUS_SUSPENDED,
    ];

    // Business Rules
    public const MIN_MONTHS_FOR_REVENUE = 2;
    public const MAX_TITLE_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 2000;
    public const MAX_URL_LENGTH = 500;

    /**
     * نام جدول
     * 
     * @var string
     */
    protected static string $table = 'content_submissions';

    /**
     * ایجاد ثبت محتوا
     * 
     * @param array $data
     * @return int|null
     * @throws \InvalidArgumentException
     */
    public function create(array $data): ?int
    {
        $this->validateCreateData($data);
        
        $now = date('Y-m-d H:i:s');

        $fields = [
            'user_id'                => (int)$data['user_id'],
            'platform'               => (string)$data['platform'],
            'video_url'              => (string)$data['video_url'],
            'title'                  => (string)$data['title'],
            'description'            => $data['description'] ?? null,
            'category'               => $data['category'] ?? null,
            'status'                 => self::STATUS_PENDING,
            'agreement_accepted'     => (int)($data['agreement_accepted'] ?? 0),
            'agreement_accepted_at'  => $data['agreement_accepted_at'] ?? null,
            'agreement_ip'           => $data['agreement_ip'] ?? null,
            'agreement_fingerprint'  => $data['agreement_fingerprint'] ?? null,
            'is_deleted'             => 0,
            'created_at'             => $now,
            'updated_at'             => $now,
        ];

        return $this->insertRecord($fields);
    }

    /**
     * یافتن رکورد با شناسه
     * 
     * @param int $id
     * @return object|null
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM " . static::$table . " WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        return $stmt ? $stmt->fetch(PDO::FETCH_OBJ) ?: null : null;
    }

    /**
     * یافتن رکورد با اطلاعات کاربر
     * 
     * @param int $id
     * @return object|null
     */
    public function findWithUser(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT cs.*, 
                    u.full_name as user_name, 
                    u.email as user_email,
                    u.phone as user_phone
             FROM " . static::$table . " cs
             JOIN users u ON cs.user_id = u.id
             WHERE cs.id = ? AND cs.is_deleted = 0
             LIMIT 1",
            [$id]
        );

        return $stmt ? $stmt->fetch(PDO::FETCH_OBJ) ?: null : null;
    }

    /**
     * لیست محتواهای کاربر
     * 
     * @param int $userId
     * @param string|null $status
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getByUser(
        int $userId, 
        ?string $status = null, 
        int $limit = 20, 
        int $offset = 0
    ): array {
        $limit  = max(1, min($limit, 100)); // Max 100 items
        $offset = max(0, $offset);

        $sql = "SELECT * FROM " . static::$table . " 
                WHERE user_id = ? AND is_deleted = 0";
        $params = [$userId];

        if ($status && in_array($status, self::ALLOWED_STATUSES, true)) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_OBJ) : [];
    }

    /**
     * شمارش محتواهای کاربر
     * 
     * @param int $userId
     * @param string|null $status
     * @return int
     */
    public function countByUser(int $userId, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) as total 
                FROM " . static::$table . " 
                WHERE user_id = ? AND is_deleted = 0";
        $params = [$userId];

        if ($status && in_array($status, self::ALLOWED_STATUSES, true)) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    /**
     * دریافت داده‌های کامل کاربر (بهینه‌شده)
     * یک query به جای چندین query
     * 
     * @param int $userId
     * @param string|null $status
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getUserContentData(
        int $userId,
        ?string $status = null,
        int $limit = 10,
        int $offset = 0
    ): array {
        // Get submissions
        $submissions = $this->getByUser($userId, $status, $limit, $offset);
        
        // Get stats in single query
        $statsStmt = $this->db->query(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected
             FROM " . static::$table . "
             WHERE user_id = ? AND is_deleted = 0",
            [
                self::STATUS_PENDING,
                self::STATUS_APPROVED,
                self::STATUS_PUBLISHED,
                self::STATUS_REJECTED,
                $userId
            ]
        );
        
        $statsRow = $statsStmt ? $statsStmt->fetch(PDO::FETCH_OBJ) : null;
        
        $stats = [
            'total' => (int)($statsRow->total ?? 0),
            'pending' => (int)($statsRow->pending ?? 0),
            'approved' => (int)($statsRow->approved ?? 0),
            'published' => (int)($statsRow->published ?? 0),
            'rejected' => (int)($statsRow->rejected ?? 0),
        ];
        
        // Get revenue stats
        $revenueStmt = $this->db->query(
            "SELECT 
                SUM(CASE WHEN status = 'paid' THEN net_user_amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = 'pending' THEN net_user_amount ELSE 0 END) as total_pending
             FROM content_revenues
             WHERE user_id = ?",
            [$userId]
        );
        
        $revenueRow = $revenueStmt ? $revenueStmt->fetch(PDO::FETCH_OBJ) : null;
        
        // M22: Safe null handling - always check before accessing properties
        $totalRevenue = 0.0;
        $pendingRevenue = 0.0;
        if ($revenueRow !== null) {
            $totalRevenue = (float)($revenueRow->total_paid ?? 0);
            $pendingRevenue = (float)($revenueRow->total_pending ?? 0);
        }
        
        // Calculate total pages
        $totalCount = $status ? $this->countByUser($userId, $status) : $stats['total'];
        $totalPages = (int)ceil($totalCount / $limit);
        
        return [
            'submissions' => $submissions,
            'stats' => $stats,
            'totalRevenue' => $totalRevenue,
            'pendingRevenue' => $pendingRevenue,
            'total' => $totalCount,
            'totalPages' => max(1, $totalPages),
        ];
    }

    /**
     * لیست تمام محتواها (ادمین)
     * 
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $sql = "SELECT cs.*, 
                       u.full_name as user_name, 
                       u.email as user_email,
                       u.phone as user_phone
                FROM " . static::$table . " cs
                JOIN users u ON cs.user_id = u.id
                WHERE cs.is_deleted = 0";

        $params = [];

        // Apply filters
        if (!empty($filters['status']) && in_array($filters['status'], self::ALLOWED_STATUSES, true)) {
            $sql .= " AND cs.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['platform']) && in_array($filters['platform'], self::ALLOWED_PLATFORMS, true)) {
            $sql .= " AND cs.platform = ?";
            $params[] = $filters['platform'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND cs.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $searchTerm = \trim((string)$filters['search']);
            $escaped = $this->escapeLikeValue($searchTerm, 100);
            $search = "%{$escaped}%";

            $sql .= " AND (cs.title LIKE ? OR cs.video_url LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY cs.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_OBJ) : [];
    }

    /**
     * شمارش کل محتواها (ادمین)
     * 
     * @param array $filters
     * @return int
     */
    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM " . static::$table . " cs
                JOIN users u ON cs.user_id = u.id
                WHERE cs.is_deleted = 0";

        $params = [];

        // Apply same filters as getAll
        if (!empty($filters['status']) && in_array($filters['status'], self::ALLOWED_STATUSES, true)) {
            $sql .= " AND cs.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['platform']) && in_array($filters['platform'], self::ALLOWED_PLATFORMS, true)) {
            $sql .= " AND cs.platform = ?";
            $params[] = $filters['platform'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND cs.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $searchTerm = \trim((string)$filters['search']);
            $escaped = $this->escapeLikeValue($searchTerm, 100);
            $search = "%{$escaped}%";

            $sql .= " AND (cs.title LIKE ? OR cs.video_url LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    /**
     * بروزرسانی رکورد
     * 
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Whitelist of allowed fields
        $allowedFields = [
            'title', 'description', 'category', 'video_url',
            'status', 'reviewed_by', 'reviewed_at', 'approved_at',
            'rejection_reason', 'admin_notes', 'agreement_accepted', 
            'agreement_accepted_at', 'agreement_ip', 'agreement_fingerprint', 'is_deleted'
        ];

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            if (\in_array($k, $allowedFields, true)) {
                $fields[] = "`{$k}` = ?";
                $values[] = $v;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = NOW()";
        $values[] = $id;

        $sql = "UPDATE `" . static::$table . "`
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    /**
     * حذف نرم
     * 
     * @param int $id
     * @return bool
     */
    public function softDelete(int $id): bool
    {
        return $this->update($id, ['is_deleted' => 1]);
    }

    /**
     * بررسی وجود محتوای در انتظار
     * 
     * @param int $userId
     * @return bool
     */
    public function hasPendingSubmission(int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total 
             FROM " . static::$table . "
             WHERE user_id = ? 
               AND status IN (?, ?) 
               AND is_deleted = 0",
            [$userId, self::STATUS_PENDING, self::STATUS_UNDER_REVIEW]
        );

        $row = $stmt ? $stmt->fetch(PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0) > 0;
    }

    /**
     * بررسی وجود URL
     * 
     * @param string $videoUrl
     * @param int|null $excludeId
     * @return bool
     */
    public function isUrlExists(string $videoUrl, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as total 
                FROM " . static::$table . " 
                WHERE video_url = ? AND is_deleted = 0";
        $params = [$videoUrl];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = (int)$excludeId;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0) > 0;
    }

    /**
     * تعداد ماه‌های فعالیت کاربر
     * 
     * @param int $userId
     * @return int
     */
    public function getActiveMonths(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT MIN(approved_at) as first_approved
             FROM " . static::$table . "
             WHERE user_id = ?
               AND status IN (?, ?)
               AND is_deleted = 0
               AND approved_at IS NOT NULL",
            [$userId, self::STATUS_APPROVED, self::STATUS_PUBLISHED]
        );

        $row = $stmt ? $stmt->fetch(PDO::FETCH_OBJ) : null;
        
        if (!$row || empty($row->first_approved)) {
            return 0;
        }

        try {
            $firstApproved = new \DateTime((string)$row->first_approved);
            $now = new \DateTime();
            $diff = $now->diff($firstApproved);

            return ($diff->y * 12) + $diff->m;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * دریافت آمار کلی
     * 
     * @return object
     */
    public function getStats(): object
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as review_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as published_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as suspended_count
             FROM " . static::$table . "
             WHERE is_deleted = 0",
            [
                self::STATUS_PENDING,
                self::STATUS_UNDER_REVIEW,
                self::STATUS_APPROVED,
                self::STATUS_PUBLISHED,
                self::STATUS_REJECTED,
                self::STATUS_SUSPENDED,
            ]
        );

        $row = $stmt ? $stmt->fetch(PDO::FETCH_OBJ) : null;
        return $row ?: (object)[];
    }

    // ============ Private Helper Methods ============

    /**
     * اعتبارسنجی داده‌های ایجاد
     * 
     * @param array $data
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateCreateData(array $data): void
    {
        if (empty($data['user_id'])) {
            throw new \InvalidArgumentException('user_id is required');
        }

        if (empty($data['platform']) || !in_array($data['platform'], self::ALLOWED_PLATFORMS, true)) {
            throw new \InvalidArgumentException('Invalid platform');
        }

        if (empty($data['video_url'])) {
            throw new \InvalidArgumentException('video_url is required');
        }

        if (strlen($data['video_url']) > self::MAX_URL_LENGTH) {
            throw new \InvalidArgumentException('video_url is too long');
        }

        if (empty($data['title'])) {
            throw new \InvalidArgumentException('title is required');
        }

        if (strlen($data['title']) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException('title is too long');
        }

        if (!empty($data['description']) && strlen($data['description']) > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException('description is too long');
        }
    }

    /**
     * درج رکورد
     * 
     * @param array $fields
     * @return int|null
     */
    private function insertRecord(array $fields): ?int
    {
        $columns = array_keys($fields);
        $values = array_values($fields);

        $placeholders = array_fill(0, count($columns), '?');
        $colsSql = '`' . implode('`,`', $columns) . '`';

        $sql = "INSERT INTO " . static::$table . " ({$colsSql}) 
                VALUES (" . implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }
}
