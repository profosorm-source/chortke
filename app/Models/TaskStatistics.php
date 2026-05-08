<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * TaskStatistics Model — Task Statistics Data Access Layer
 * 
 * مسئولیت: آمارگیری از تسک‌ها و submissions
 * استفاده می‌شود در: KpiService, AnalyticsService
 */
class TaskStatistics extends Model
{
    /**
     * کل تسک‌ها
     */
    public function getTotalTasks(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM custom_tasks");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * تسک‌های فعال
     */
    public function getActiveTasks(): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM custom_tasks WHERE status = 'active' AND end_date > NOW()"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * کل submissions
     */
    public function getTotalSubmissions(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM custom_task_submissions");
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * آمارهای تسک بر حسب وضعیت
     */
    public function getTasksByStatus(): array
    {
        $stmt = $this->db->prepare(
            "SELECT status, COUNT(*) as count
             FROM custom_tasks
             GROUP BY status
             ORDER BY count DESC"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمارهای submissions بر حسب وضعیت
     */
    public function getSubmissionsByStatus(): array
    {
        $stmt = $this->db->prepare(
            "SELECT status, COUNT(*) as count
             FROM custom_task_submissions
             GROUP BY status
             ORDER BY count DESC"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * تسک‌های با بالاترین درخواست
     */
    public function getPopularTasks(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT ct.id, ct.title, COUNT(cts.id) as submission_count
             FROM custom_tasks ct
             LEFT JOIN custom_task_submissions cts ON ct.id = cts.task_id
             GROUP BY ct.id, ct.title
             ORDER BY submission_count DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * میانگین تعداد submissions برای تسک
     */
    public function getAverageSubmissionsPerTask(): float
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) / (SELECT COUNT(*) FROM custom_tasks WHERE status != 'closed') as avg
             FROM custom_task_submissions"
        );
        $stmt->execute();

        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * آمار تسک‌ها بر حسب دسته‌بندی
     */
    public function getTasksByCategory(): array
    {
        $stmt = $this->db->prepare(
            "SELECT category, COUNT(*) as count, AVG(budget) as avg_budget
             FROM custom_tasks
             GROUP BY category
             ORDER BY count DESC"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمار تسک‌های ایجاد شده درروز معین
     */
    public function getDailyTaskCreation(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM custom_tasks
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC"
        );
        $stmt->execute([$days]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * میانگین امتیاز تسک
     */
    public function getAverageTaskRating(): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(rating) FROM task_rating WHERE task_id IS NOT NULL"
        );
        $stmt->execute();

        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * تسک‌های با بالاترین امتیاز
     */
    public function getHighestRatedTasks(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT ct.id, ct.title, AVG(tr.rating) as avg_rating, COUNT(tr.id) as rating_count
             FROM custom_tasks ct
             LEFT JOIN task_rating tr ON ct.id = tr.task_id
             GROUP BY ct.id, ct.title
             HAVING avg_rating > 0
             ORDER BY avg_rating DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * خلاصه آمارهای تسک
     */
    public function getTaskSummary(): array
    {
        return [
            'total_tasks' => $this->getTotalTasks(),
            'active_tasks' => $this->getActiveTasks(),
            'total_submissions' => $this->getTotalSubmissions(),
            'tasks_by_status' => $this->getTasksByStatus(),
            'submissions_by_status' => $this->getSubmissionsByStatus(),
            'avg_submissions_per_task' => $this->getAverageSubmissionsPerTask(),
            'avg_task_rating' => $this->getAverageTaskRating(),
            'tasks_by_category' => $this->getTasksByCategory(),
        ];
    }
}
