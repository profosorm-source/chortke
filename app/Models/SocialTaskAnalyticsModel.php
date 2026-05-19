<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * SocialTaskAnalyticsModel - Handles Ratings, User Trust, Adjustments, Snapshots, and Execution Stats
 */
class SocialTaskAnalyticsModel extends Model
{
    protected static string $table = 'social_ratings';

    // --- Rating Operations ---

    public function createRating(array $data): int
    {
        // ۱. بررسی وضعیت execution (فقط تسک‌های با موفقیت انجام‌شده قابل امتیازدهی هستند)
        $exec = $this->db->fetch(
            "SELECT id FROM social_task_executions 
             WHERE id = ? AND status IN ('approved', 'soft_approved') LIMIT 1",
            [$data['execution_id']]
        );
        
        if (!$exec) {
            throw new \RuntimeException('Cannot rate incomplete or rejected execution');
        }

        // ۲. بررسی عدم ارسال امتیاز تکراری
        if ($this->hasUserRated($data['execution_id'], $data['rater_id'], $data['rater_type'])) {
            throw new \RuntimeException('You have already rated this execution');
        }

        return $this->db->insert(
            "INSERT INTO social_ratings
               (execution_id, rater_id, rated_id, rater_type, stars, comment, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())",
            [
                $data['execution_id'],
                $data['rater_id'],
                $data['rated_id'],
                $data['rater_type'],
                $data['stars'],
                $data['comment']
            ]
        );
    }

    public function getAvgRating(int $userId, string $raterType): ?object
    {
        return $this->db->fetch(
            "SELECT AVG(stars) AS avg_stars, COUNT(*) AS total_ratings
             FROM social_ratings
             WHERE rated_id = ? AND rater_type = ?",
            [$userId, $raterType]
        );
    }

    public function getUserRatingHistory(int $userId, string $raterType, int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT sr.stars, sr.comment, sr.created_at, u.full_name AS rater_name
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             WHERE sr.rated_id = ? AND sr.rater_type = ? AND sr.status = 'approved'
             ORDER BY sr.created_at DESC
             LIMIT ?",
            [$userId, $raterType, $limit]
        );
    }

    public function getPendingRatings(int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT sr.*, u.full_name AS rater_name, rated.full_name AS rated_name, sa.title AS ad_title
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             JOIN users rated ON rated.id = sr.rated_id
             JOIN social_task_executions ste ON ste.id = sr.execution_id
             JOIN ads sa ON sa.id = ste.ad_id
             WHERE sr.status = 'pending'
             ORDER BY sr.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function getRatingById(int $id): ?object
    {
        return $this->db->fetch(
            "SELECT sr.*, u.full_name AS rater_name, rated.full_name AS rated_name, sa.title AS ad_title
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             JOIN users rated ON rated.id = sr.rated_id
             JOIN social_task_executions ste ON ste.id = sr.execution_id
             JOIN ads sa ON sa.id = ste.ad_id
             WHERE sr.id = ?",
            [$id]
        );
    }

    public function updateRatingStatus(int $id, string $status, int $adminId): bool
    {
        return (bool)$this->db->query(
            "UPDATE social_ratings SET status = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW() WHERE id = ?",
            [$status, $adminId, $id]
        );
    }

    public function getRatingStats(): ?object
    {
        return $this->db->fetch(
            "SELECT
                SUM(status = 'pending') AS pending_reviews,
                SUM(status = 'approved') AS approved_reviews,
                SUM(status = 'rejected') AS rejected_reviews
             FROM social_ratings"
        );
    }

    public function getRatingHistoryFull(int $userId, string $column, int $limit, int $offset): array
    {
        $allowedColumns = ['rater_id', 'rated_id'];
        if (!in_array($column, $allowedColumns, true)) {
            throw new \InvalidArgumentException('Invalid column name');
        }

        return $this->db->fetchAll(
            "SELECT sr.*, u.full_name AS rater_name, rated.full_name AS rated_name, sa.title AS ad_title
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rater_id
             JOIN users rated ON rated.id = sr.rated_id
             JOIN social_task_executions ste ON ste.id = sr.execution_id
             JOIN ads sa ON sa.id = ste.ad_id
             WHERE sr.{$column} = ?
             ORDER BY sr.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    public function hasUserRated(int $executionId, int $raterId, string $raterType): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM social_ratings
             WHERE execution_id = ? AND rater_id = ? AND rater_type = ? LIMIT 1",
            [$executionId, $raterId, $raterType]
        );
        return (bool)$row;
    }

    public function recalculateUserStats(int $userId, string $type): bool
    {
        $raterType = $type === 'advertiser' ? 'executor' : 'advertiser';
        
        $stats = $this->db->fetch("
            SELECT AVG(stars) as avg_rating, COUNT(*) as count
            FROM social_ratings
            WHERE rated_id = ? AND rater_type = ? AND status = 'approved'
        ", [$userId, $raterType]);
        
        $colRating = $type === 'advertiser' ? 'social_advertiser_rating' : 'social_executor_rating';
        
        return (bool)$this->db->query(
            "UPDATE users SET {$colRating} = ?, social_rating_count = ? WHERE id = ?",
            [$stats->avg_rating ?? 0, $stats->count ?? 0, $userId]
        );
    }

    // --- Trust & Scoring Operations ---

    public function getUserTrust(int $userId, bool $forUpdate = false): ?object
    {
        $sql = "SELECT trust_score FROM social_user_trust WHERE user_id = ? LIMIT 1";
        if ($forUpdate) {
            if (!$this->db->inTransaction()) {
                throw new \RuntimeException('Database transaction required for pessimistic locking in getUserTrust');
            }
            $sql .= " FOR UPDATE";
        }
        return $this->db->fetch($sql, [$userId]);
    }

    public function upsertUserTrust(int $userId, float $score): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO social_user_trust (user_id, trust_score, updated_at, created_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE trust_score = ?, updated_at = NOW()",
            [$userId, $score, $score]
        );
    }

    public function recordTrustAdjustment(array $data): bool
    {
        return (bool)$this->db->insert(
            "INSERT INTO social_trust_adjustments
             (user_id, admin_id, delta, old_trust, new_trust, reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [
                $data['user_id'],
                $data['admin_id'],
                $data['delta'],
                $data['old_trust'],
                $data['new_trust'],
                $data['reason']
            ]
        );
    }

    public function saveTrustSnapshot(array $data): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO social_trust_snapshots
               (user_id, trust_score, week_good_tasks, week_rejected, week_soft, snapshot_date)
             VALUES (?, ?, ?, ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE
               trust_score = VALUES(trust_score),
               week_good_tasks = VALUES(week_good_tasks),
               week_rejected = VALUES(week_rejected),
               week_soft = VALUES(week_soft)",
            [
                $data['user_id'],
                $data['trust_score'],
                $data['week_good_tasks'],
                $data['week_rejected'],
                $data['week_soft']
            ]
        );
    }

    // --- Statistics Operations ---

    public function getExecutorStats(int $userId): ?object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(decision = 'approved') AS approved,
                SUM(decision = 'soft_approved') AS soft_approved,
                SUM(decision = 'rejected') AS rejected,
                AVG(task_score) AS avg_score,
                SUM(CASE WHEN decision IN ('approved','soft_approved') THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*),0) AS success_rate
             FROM social_task_executions
             WHERE executor_id = ?",
            [$userId]
        );
    }

    public function getAdvertiserAdStats(int $adId, int $advertiserId): ?object
    {
        return $this->db->fetch(
            "SELECT
                sa.*,
                COUNT(ste.id) AS total_executions,
                SUM(ste.decision = 'approved') AS approved,
                SUM(ste.decision = 'soft_approved') AS soft_approved,
                SUM(ste.decision = 'rejected') AS rejected,
                AVG(ste.task_score) AS avg_score,
                AVG(ste.active_time) AS avg_time
             FROM ads sa
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE sa.id = ? AND sa.user_id = ?
             GROUP BY sa.id
             LIMIT 1",
            [$adId, $advertiserId]
        );
    }

    public function getWeeklyExecutionStats(int $userId): ?object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN decision = 'approved' AND task_score >= 70 THEN 1 ELSE 0 END) AS good_tasks,
                SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN decision = 'soft_approved' THEN 1 ELSE 0 END) AS soft_approved,
                AVG(task_score) AS avg_score
             FROM social_task_executions
             WHERE executor_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );
    }

    public function getRecentActiveExecutors(int $days = 7): array
    {
        return $this->db->fetchAll(
            "SELECT DISTINCT executor_id AS user_id
             FROM social_task_executions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }

    public function getExecutorHistory(int $userId, int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT ste.*, sa.title, sa.platform, sa.task_type, sa.price_per_task
             FROM social_task_executions ste
             JOIN ads sa ON sa.id = ste.ad_id
             WHERE ste.executor_id = ?
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    public function getMedianReward(): float
    {
        $row = $this->db->fetch("SELECT price_per_task FROM ads WHERE status = 'active' ORDER BY price_per_task LIMIT 1 OFFSET (SELECT COUNT(*) / 2 FROM ads WHERE status = 'active')");
        return (float)($row->price_per_task ?? 0);
    }
}
