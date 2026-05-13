<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * TaskRating Model
 */
class TaskRating extends Model
{
    protected static string $table = 'task_ratings';

    /**
     * یافتن یک رتبه‌بندی
     */
    public function find(int $id): ?object
    {
        return $this->db->table(static::$table . ' as r')
            ->select('r.*', 'u.full_name AS rater_name', 'ct.title AS task_title')
            ->leftJoin('users as u', 'u.id', '=', 'r.rater_id')
            ->leftJoin('custom_tasks as ct', 'ct.id', '=', 'r.task_id')
            ->where('r.id', '=', $id)
            ->first();
    }

    /**
     * بررسی آیا قبلاً امتیاز داده شده
     */
    public function hasRated(int $submissionId, int $raterId, string $ratingType): bool
    {
        return $this->db->table(static::$table)
            ->where('submission_id', '=', $submissionId)
            ->where('rater_id', '=', $raterId)
            ->where('rating_type', '=', $ratingType)
            ->exists();
    }

    /**
     * محاسبه میانگین امتیاز یک کاربر
     */
    public function getAverageRating(int $userId, string $ratingType): array
    {
        $result = $this->db->table(static::$table)
            ->selectRaw('COUNT(*) as total_ratings')
            ->selectRaw('AVG(rating) as average_rating')
            ->selectRaw('SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star')
            ->selectRaw('SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star')
            ->selectRaw('SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star')
            ->selectRaw('SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star')
            ->selectRaw('SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star')
            ->where('rated_user_id', '=', $userId)
            ->where('rating_type', '=', $ratingType)
            ->first();

        return [
            'total' => (int)($result->total_ratings ?? 0),
            'average' => round((float)($result->average_rating ?? 0), 2),
            'distribution' => [
                5 => (int)($result->five_star ?? 0),
                4 => (int)($result->four_star ?? 0),
                3 => (int)($result->three_star ?? 0),
                2 => (int)($result->two_star ?? 0),
                1 => (int)($result->one_star ?? 0),
            ]
        ];
    }

    /**
     * دریافت نظرات یک کاربر
     */
    public function getUserRatings(int $userId, string $ratingType, int $limit = 20, int $offset = 0): array
    {
        return $this->db->table(static::$table . ' as r')
            ->select('r.*', 'u.full_name AS rater_name', 'ct.title AS task_title')
            ->leftJoin('users as u', 'u.id', '=', 'r.rater_id')
            ->leftJoin('custom_tasks as ct', 'ct.id', '=', 'r.task_id')
            ->where('r.rated_user_id', '=', $userId)
            ->where('r.rating_type', '=', $ratingType)
            ->orderBy('r.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * دریافت نظرات یک تسک
     */
    public function getTaskRatings(int $taskId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->table(static::$table . ' as r')
            ->select('r.*', 'u.full_name AS rater_name')
            ->leftJoin('users as u', 'u.id', '=', 'r.rater_id')
            ->where('r.task_id', '=', $taskId)
            ->orderBy('r.created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }
}
