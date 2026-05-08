<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * CustomTaskModel — Main Model for Tasks, delegating Submissions & Analytics for High Cohesion
 */
class CustomTaskModel extends Model
{
    protected static string $table = 'custom_tasks';

    private CustomTaskSubmissionModel $submissionModel;
    private CustomTaskAnalyticsModel $analyticsModel;

    public function __construct(\Core\Database $db)
    {
        parent::__construct($db);
        $this->submissionModel = new CustomTaskSubmissionModel($db);
        $this->analyticsModel = new CustomTaskAnalyticsModel($db);
    }

    // ==================== TASK METHODS (Core) ====================

    /**
     * یافتن یک تسک
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ct.*, 
                   u.full_name AS creator_name, 
                   u.email AS creator_email,
                   (ct.total_quantity - ct.completed_quantity) AS remaining_count
            FROM custom_tasks ct
            LEFT JOIN users u ON u.id = ct.creator_id
            WHERE ct.id = ? AND ct.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ?: null;
    }

    /**
     * ایجاد تسک جدید
     */
    public function create(array $d): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO custom_tasks
            (creator_id, title, description, link, task_type, proof_type, proof_description,
             sample_image, price_per_task, currency, total_budget, total_quantity,
             deadline_hours, country_restriction, device_restriction, os_restriction,
             status, site_fee_percent, site_fee_amount,
             created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
        ");

        $result = $stmt->execute([
            $d['creator_id'],
            $d['title'],
            $d['description'],
            $d['link'] ?? null,
            $d['task_type'] ?? 'custom',
            $d['proof_type'] ?? 'screenshot',
            $d['proof_description'] ?? null,
            $d['sample_image'] ?? null,
            $d['price_per_task'],
            $d['currency'] ?? 'irt',
            $d['total_budget'] ?? 0,
            $d['total_quantity'] ?? 1,
            $d['deadline_hours'] ?? 24,
            $d['country_restriction'] ?? null,
            $d['device_restriction'] ?? 'all',
            $d['os_restriction'] ?? null,
            $d['status'] ?? 'draft',
            $d['site_fee_percent'] ?? 0,
            $d['site_fee_amount'] ?? 0,
        ]);

        if (!$result) return null;

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $this->find($id) : null;
    }

    /**
     * بروز‌رسانی تسک
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = [
            'title','description','link','task_type','proof_type','proof_description',
            'sample_image','price_per_task','total_quantity','deadline_hours',
            'country_restriction','device_restriction','os_restriction',
            'status','rejection_reason',
            'approved_by','approved_at','total_budget','remaining_budget',
            'completed_quantity','site_fee_percent','site_fee_amount',
        ];

        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }

        if (empty($fields)) return false;

        $fields[] = "updated_at = NOW()";
        $values[] = $id;

        $stmt = $this->db->prepare("
            UPDATE custom_tasks SET " . \implode(', ', $fields) . "
            WHERE id = ? AND deleted_at IS NULL
        ");

        return $stmt->execute($values);
    }

    /**
     * حذف نرم تسک
     */
    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE custom_tasks SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * تسک‌های تکمیل‌شده کاملاً
     */
    public function getFullyCompletedTasks(): array
    {
        $stmt = $this->db->prepare("
            SELECT id, creator_id, total_budget, spent_budget, currency
            FROM custom_tasks
            WHERE status = 'active'
              AND completed_count >= total_quantity
              AND deleted_at IS NULL
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * کاهش تعداد pending برای تسک
     */
    public function decrementPendingCount(int $taskId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE custom_tasks
             SET pending_count = GREATEST(0, pending_count - 1)
             WHERE id = ?"
        );
        return $stmt->execute([$taskId]);
    }

    /**
     * افزایش تعداد تکمیل‌شده و خرج بودجه
     */
    public function incrementCompletedCountAndSpend(int $taskId, float $amount): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE custom_tasks
             SET completed_count = completed_count + 1,
                 pending_count = GREATEST(0, pending_count - 1),
                 spent_budget = spent_budget + ?
             WHERE id = ?"
        );
        return $stmt->execute([$amount, $taskId]);
    }

    /**
     * علامت‌گذاری تسک به عنوان تکمیل‌شده
     */
    public function markCompleted(int $taskId): bool
    {
        $stmt = $this->db->prepare("UPDATE custom_tasks SET status = 'completed' WHERE id = ?");
        return $stmt->execute([$taskId]);
    }

    /**
     * لیست تسک‌های فعال (برای کاربر انجام‌دهنده)
     */
    public function getAvailable(int $workerId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = [
            "ct.status = 'active'",
            "ct.deleted_at IS NULL",
            "ct.creator_id != ?",
            "(ct.total_quantity - ct.completed_count - ct.pending_count) > 0",
        ];

        $params = [$workerId];

        if (!empty($filters['task_type'])) {
            $where[] = "ct.task_type = ?";
            $params[] = $filters['task_type'];
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT ct.*, 
                   u.full_name AS creator_name,
                   (ct.total_quantity - ct.completed_count - ct.pending_count) AS remaining_count
            FROM custom_tasks ct
            LEFT JOIN users u ON u.id = ct.creator_id
            WHERE {$whereStr}
            ORDER BY ct.is_featured DESC, ct.priority DESC, ct.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش تسک‌های فعال
     */
    public function countAvailable(int $workerId, array $filters = []): int
    {
        $where = [
            "ct.status = 'active'",
            "ct.deleted_at IS NULL",
            "ct.creator_id != ?",
            "(ct.total_quantity - ct.completed_count - ct.pending_count) > 0",
        ];

        $params = [$workerId];

        if (!empty($filters['task_type'])) {
            $where[] = "ct.task_type = ?";
            $params[] = $filters['task_type'];
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM custom_tasks ct WHERE {$whereStr}");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * تسک‌های تبلیغ‌دهنده
     */
    public function getByCreator(int $creatorId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = ["ct.creator_id = ?", "ct.deleted_at IS NULL"];
        $params = [$creatorId];

        if ($status) {
            $where[] = "ct.status = ?";
            $params[] = $status;
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT ct.*,
                   (ct.total_quantity - ct.completed_count - ct.pending_count) AS remaining_count
            FROM custom_tasks ct
            WHERE {$whereStr}
            ORDER BY ct.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * لیست ادمین برای تسک‌ها
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $where = ["ct.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "ct.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['task_type'])) {
            $where[] = "ct.task_type = ?";
            $params[] = $filters['task_type'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(ct.title LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT ct.*, 
                   u.full_name AS creator_name, 
                   u.email AS creator_email,
                   (ct.total_quantity - ct.completed_count - ct.pending_count) AS remaining_count
            FROM custom_tasks ct
            LEFT JOIN users u ON u.id = ct.creator_id
            WHERE {$whereStr}
            ORDER BY ct.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش لیست ادمین برای تسک‌ها
     */
    public function adminCount(array $filters = []): int
    {
        $where = ["ct.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "ct.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['task_type'])) {
            $where[] = "ct.task_type = ?";
            $params[] = $filters['task_type'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(ct.title LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM custom_tasks ct
            LEFT JOIN users u ON u.id = ct.creator_id
            WHERE {$whereStr}
        ");

        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // ==================== SUBMISSIONS DELEGATION ====================

    public function submission_find(int $id): ?object
    {
        return $this->submissionModel->submission_find($id);
    }

    public function submission_create(array $d): ?object
    {
        return $this->submissionModel->submission_create($d);
    }

    public function submission_update(int $id, array $data): bool
    {
        return $this->submissionModel->submission_update($id, $data);
    }

    public function submission_hasWorkerDone(int $taskId, int $workerId): bool
    {
        return $this->submissionModel->submission_hasWorkerDone($taskId, $workerId);
    }

    public function submission_todayCount(int $workerId): int
    {
        return $this->submissionModel->submission_todayCount($workerId);
    }

    public function submission_isDuplicateImage(string $hash, int $taskId): bool
    {
        return $this->submissionModel->submission_isDuplicateImage($hash, $taskId);
    }

    public function submission_getByTask(int $taskId, ?string $status = null, int $limit = 30, int $offset = 0): array
    {
        return $this->submissionModel->submission_getByTask($taskId, $status, $limit, $offset);
    }

    public function submission_getByWorker(int $workerId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        return $this->submissionModel->submission_getByWorker($workerId, $status, $limit, $offset);
    }

    public function submission_getExpiredSubmissions(): array
    {
        return $this->submissionModel->submission_getExpiredSubmissions();
    }

    public function submission_getUnreviewedSubmissions(int $hours = 48): array
    {
        return $this->submissionModel->submission_getUnreviewedSubmissions($hours);
    }

    public function submission_findWithTask(int $id): ?object
    {
        return $this->submissionModel->submission_findWithTask($id);
    }

    public function submission_markExpired(int $id): bool
    {
        return $this->submissionModel->submission_markExpired($id);
    }

    public function submission_markApproved(int $id): bool
    {
        return $this->submissionModel->submission_markApproved($id);
    }

    public function submission_markRewardPaid(int $id): bool
    {
        return $this->submissionModel->submission_markRewardPaid($id);
    }

    public function submission_checkIdempotency(string $key): bool
    {
        return $this->submissionModel->submission_checkIdempotency($key);
    }

    public function getOldSubmissionsForAutoApproval(int $hoursOld = 48): array
    {
        return $this->submissionModel->getOldSubmissionsForAutoApproval($hoursOld);
    }

    public function getAdminSubmissionStats(): array
    {
        return $this->submissionModel->getAdminSubmissionStats();
    }

    // ==================== ANALYTICS DELEGATION ====================

    public function analytics_recordView(int $taskId): void
    {
        $this->analyticsModel->analytics_recordView($taskId);
    }

    public function analytics_recordStart(int $taskId): void
    {
        $this->analyticsModel->analytics_recordStart($taskId);
    }

    public function analytics_recordSubmission(int $taskId): void
    {
        $this->analyticsModel->analytics_recordSubmission($taskId);
    }

    public function analytics_recordApproval(int $taskId): void
    {
        $this->analyticsModel->analytics_recordApproval($taskId);
    }

    public function analytics_recordRejection(int $taskId): void
    {
        $this->analyticsModel->analytics_recordRejection($taskId);
    }

    public function analytics_getTaskStats(int $taskId, int $days = 30): array
    {
        return $this->analyticsModel->analytics_getTaskStats($taskId, $days);
    }

    public function analytics_getTrendingTasks(int $limit = 10): array
    {
        return $this->analyticsModel->analytics_getTrendingTasks($limit);
    }

    public function analytics_getCreatorDashboard(int $userId): array
    {
        return $this->analyticsModel->analytics_getCreatorDashboard($userId);
    }

    public function analytics_getWorkerDashboard(int $userId): array
    {
        return $this->analyticsModel->analytics_getWorkerDashboard($userId);
    }

    public function recordTaskView(int $taskId): bool
    {
        return $this->analyticsModel->recordTaskView($taskId);
    }

    public function getTaskAnalytics(int $taskId): array
    {
        return $this->analyticsModel->getTaskAnalytics($taskId);
    }

    public function getAdminTaskStats(): array
    {
        return $this->analyticsModel->getAdminTaskStats();
    }

    // ==================== RATINGS & FAVORITES & METADATA ====================

    public function taskTypes(): array
    {
        return [
            'signup'  => 'ثبت‌نام',
            'install' => 'نصب برنامه',
            'review'  => 'نظر دادن',
            'vote'    => 'رأی دادن',
            'follow'  => 'دنبال کردن',
            'join'    => 'عضویت',
            'custom'  => 'سفارشی',
        ];
    }

    public function proofTypes(): array
    {
        return [
            'screenshot' => 'اسکرین‌شات',
            'text'       => 'متن',
            'video'      => 'ویدیو',
            'code'       => 'کد رفرال',
            'file'       => 'فایل',
        ];
    }

    public function statusLabels(): array
    {
        return [
            'draft'          => 'پیشنویس',
            'pending_review' => 'در انتظار بررسی',
            'active'         => 'فعال',
            'paused'         => 'متوقف',
            'completed'      => 'تکمیل‌شده',
            'rejected'       => 'رد شده',
            'expired'        => 'منقضی',
        ];
    }

    public function statusClasses(): array
    {
        return [
            'draft'          => 'badge-secondary',
            'pending_review' => 'badge-warning',
            'active'         => 'badge-success',
            'paused'         => 'badge-info',
            'completed'      => 'badge-primary',
            'rejected'       => 'badge-danger',
            'expired'        => 'badge-danger',
        ];
    }

    public function submissionStatusLabels(): array
    {
        return [
            'in_progress' => 'در حال انجام',
            'submitted'   => 'ارسال شده',
            'approved'    => 'تایید شده',
            'rejected'    => 'رد شده',
            'expired'     => 'منقضی شده',
            'disputed'    => 'در اختلاف',
        ];
    }

    public function updateTaskRatingStats(int $taskId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total, AVG(rating) as average
            FROM task_ratings
            WHERE task_id = ?
        ");
        $stmt->execute([$taskId]);
        $stats = $stmt->fetch(\PDO::FETCH_OBJ);

        if ($stats) {
            return $this->update($taskId, [
                'average_rating' => round($stats->average, 2),
                'total_ratings' => $stats->total,
            ]);
        }
        return true;
    }

    public function isTaskFavorited(int $taskId, int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM task_favorites WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$taskId, $userId]);
        return $stmt->fetch() !== false;
    }

    public function addToFavorites(int $taskId, int $userId): bool
    {
        $stmt = $this->db->prepare("INSERT INTO task_favorites (task_id, user_id, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$taskId, $userId]);

        $stmt = $this->db->prepare("UPDATE custom_tasks SET favorite_count = favorite_count + 1 WHERE id = ?");
        return $stmt->execute([$taskId]);
    }

    public function removeFromFavorites(int $taskId, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM task_favorites WHERE task_id = ? AND user_id = ?");
        $stmt->execute([$taskId, $userId]);

        $stmt = $this->db->prepare("UPDATE custom_tasks SET favorite_count = favorite_count - 1 WHERE id = ?");
        return $stmt->execute([$taskId]);
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        $this->db->rollBack();
    }
}
