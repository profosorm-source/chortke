<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * CustomTaskAnalyticsModel - Custom Task Analytics, Performance Dashboards & Stats Data Access Layer
 */
class CustomTaskAnalyticsModel extends Model
{
    protected static string $table = 'task_analytics';

    public function analytics_recordView(int $taskId): void
    {
        $this->analytics_incrementDailyMetric($taskId, 'views');
        $this->db->prepare("UPDATE ads SET impressions = impressions + 1 WHERE id = ?")->execute([$taskId]);
    }

    public function analytics_recordStart(int $taskId): void
    {
        $this->analytics_incrementDailyMetric($taskId, 'starts');
    }

    public function analytics_recordSubmission(int $taskId): void
    {
        $this->analytics_incrementDailyMetric($taskId, 'submissions');
    }

    public function analytics_recordApproval(int $taskId): void
    {
        $this->analytics_incrementDailyMetric($taskId, 'approvals');
    }

    public function analytics_recordRejection(int $taskId): void
    {
        $this->analytics_incrementDailyMetric($taskId, 'rejections');
    }

    private function analytics_incrementDailyMetric(int $taskId, string $metric): void
    {
        $allowedMetrics = ['views', 'starts', 'submissions', 'approvals', 'rejections'];
        if (!in_array($metric, $allowedMetrics, true)) {
            throw new \InvalidArgumentException("Invalid metric: {$metric}");
        }

        $stmt = $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, {$metric})
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE {$metric} = {$metric} + 1
        ");
        $stmt->execute([$taskId]);
    }

    public function analytics_getTaskStats(int $taskId, int $days = 30): array
    {
        $stmt = $this->db->prepare("SELECT * FROM task_stats_view WHERE id = ?");
        $stmt->execute([$taskId]);
        $overall = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare(
            "SELECT
                date,
                views,
                starts,
                submissions,
                approvals,
                rejections,
                CASE
                    WHEN submissions > 0
                    THEN ROUND((approvals / submissions) * 100, 2)
                    ELSE 0
                END as approval_rate
            FROM task_analytics
            WHERE task_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY date DESC"
        );
        $stmt->execute([$taskId, $days]);
        $daily = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $funnel = [
            'views' => (int) ($overall['view_count'] ?? 0),
            'starts' => (int) ($overall['unique_workers'] ?? 0),
            'submissions' => (int) (($overall['approved_count'] ?? 0) + ($overall['rejected_count'] ?? 0)),
            'approvals' => (int) ($overall['approved_count'] ?? 0),
        ];

        $funnel['view_to_start'] = $funnel['views'] > 0
            ? round(($funnel['starts'] / $funnel['views']) * 100, 2)
            : 0;

        $funnel['start_to_submit'] = $funnel['starts'] > 0
            ? round(($funnel['submissions'] / $funnel['starts']) * 100, 2)
            : 0;

        $funnel['submit_to_approve'] = $funnel['submissions'] > 0
            ? round(($funnel['approvals'] / $funnel['submissions']) * 100, 2)
            : 0;

        return [
            'overall' => $overall,
            'daily' => $daily,
            'funnel' => $funnel,
        ];
    }

    public function analytics_getTrendingTasks(int $limit = 10): array
    {
        $limit = \max(1, $limit);
        $stmt = $this->db->prepare(
            "SELECT id, title, user_id AS creator_id, currency, impressions AS view_count, total_budget, (total_budget - remaining_budget) AS spent_budget, completed_count, pending_count
             FROM ads
             WHERE deleted_at IS NULL
             ORDER BY impressions DESC, created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function analytics_getCreatorDashboard(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                status,
                COUNT(*) as count,
                SUM(total_budget) as total_budget,
                SUM(total_budget - remaining_budget) as spent_budget
            FROM ads
            WHERE user_id = ? AND deleted_at IS NULL
            GROUP BY status"
        );
        $stmt->execute([$userId]);
        $tasksByStatus = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) as total_submissions,
                SUM(CASE WHEN s.status = 'submitted' THEN 1 ELSE 0 END) as pending_review,
                SUM(CASE WHEN s.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN s.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                AVG(CASE
                    WHEN s.status = 'approved'
                    THEN TIMESTAMPDIFF(MINUTE, s.submitted_at, s.reviewed_at)
                    ELSE NULL
                END) as avg_review_time_minutes
            FROM custom_task_submissions s
            INNER JOIN ads t ON t.id = s.task_id
            WHERE t.user_id = ?"
        );
        $stmt->execute([$userId]);
        $submissions = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare(
            "SELECT
                AVG(rating) as avg_rating,
                COUNT(*) as total_ratings
            FROM task_ratings
            WHERE rated_user_id = ? AND rating_type = 'creator'"
        );
        $stmt->execute([$userId]);
        $rating = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'tasks_by_status' => $tasksByStatus,
            'submissions' => $submissions,
            'rating' => $rating,
        ];
    }

    public function analytics_getWorkerDashboard(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*) as total_tasks,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'approved' THEN reward_amount ELSE 0 END) as total_earned,
                AVG(CASE
                    WHEN status = 'approved' AND completion_time_minutes IS NOT NULL
                    THEN completion_time_minutes
                    ELSE NULL
                END) as avg_completion_time
            FROM custom_task_submissions
            WHERE worker_id = ?"
        );
        $stmt->execute([$userId]);
        $submissions = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->db->prepare(
            "SELECT
                AVG(rating) as avg_rating,
                COUNT(*) as total_ratings
            FROM task_ratings
            WHERE rated_user_id = ? AND rating_type = 'worker'"
        );
        $stmt->execute([$userId]);
        $rating = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'submissions' => $submissions,
            'rating' => $rating,
        ];
    }

    public function recordTaskView(int $taskId): bool
    {
        // افزایش شمارنده بازدید
        $stmt = $this->db->prepare("UPDATE ads SET impressions = impressions + 1 WHERE id = ? AND type = 'custom_task'");
        $stmt->execute([$taskId]);

        // ثبت در آمار روزانه
        $stmt = $this->db->prepare("
            INSERT INTO task_analytics (task_id, date, views)
            VALUES (?, CURDATE(), 1)
            ON DUPLICATE KEY UPDATE views = views + 1
        ");
        return $stmt->execute([$taskId]);
    }

    public function getTaskAnalytics(int $taskId): array
    {
        // آمار کلی از view
        $stmt = $this->db->prepare("SELECT * FROM task_stats_view WHERE id = ?");
        $stmt->execute([$taskId]);
        $overall = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        // آمار روزانه
        $stmt = $this->db->prepare("
            SELECT date, views, starts, submissions, approvals, rejections
            FROM task_analytics
            WHERE task_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY date DESC
        ");
        $stmt->execute([$taskId]);
        $daily = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'overall' => $overall,
            'daily' => $daily,
        ];
    }

    public function getAdminTaskStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                status,
                COUNT(*) as count,
                SUM(total_budget) as total_budget,
                SUM(total_budget - remaining_budget) as spent_budget,
                AVG(price_per_task) as avg_price
            FROM ads
            WHERE type = 'custom_task' AND deleted_at IS NULL
            GROUP BY status"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
