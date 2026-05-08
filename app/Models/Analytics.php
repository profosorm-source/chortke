<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class Analytics extends Model
{
    protected static string $table = '';

    public function getUserMetrics(string $start): object
    {
        $totalUsers = $this->db->fetch("SELECT COUNT(*) AS total FROM users");
        $activeUsers = $this->db->fetch(
            "SELECT COUNT(DISTINCT user_id) AS total FROM activity_logs WHERE created_at >= ?",
            [$start]
        );

        $newUsers = $this->db->fetch(
            "SELECT COUNT(*) AS total FROM users WHERE created_at >= ?",
            [$start]
        );

        $usersByLevel = $this->db->fetchAll(
            "SELECT level_id, level_name, COUNT(*) AS count
             FROM users u
             LEFT JOIN user_levels ul ON u.level_id = ul.id
             GROUP BY level_id, level_name
             ORDER BY count DESC"
        ) ?? [];

        $kycStatus = $this->db->fetch(
            "SELECT
                SUM(kyc_status = 'verified') AS verified,
                SUM(kyc_status = 'pending') AS pending,
                SUM(kyc_status = 'rejected') AS rejected,
                SUM(kyc_status IS NULL) AS not_submitted
             FROM users"
        );

        return (object)[
            'total_users' => (int)($totalUsers->total ?? 0),
            'active_users' => (int)($activeUsers->total ?? 0),
            'new_users' => (int)($newUsers->total ?? 0),
            'users_by_level' => $usersByLevel,
            'kyc_status' => [
                'verified' => (int)($kycStatus->verified ?? 0),
                'pending' => (int)($kycStatus->pending ?? 0),
                'rejected' => (int)($kycStatus->rejected ?? 0),
                'not_submitted' => (int)($kycStatus->not_submitted ?? 0),
            ],
        ];
    }

    public function getUserGrowthChart(int $days): array
    {
        return $this->db->fetchAll(
            "SELECT DATE(created_at) AS date,
                    COUNT(*) AS new_users,
                    (SELECT COUNT(*) FROM users WHERE DATE(created_at) <= DATE(u.created_at)) AS cumulative
             FROM users u
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        ) ?? [];
    }

    public function getTransactionMetrics(string $start): object
    {
        $deposits = $this->db->fetch(
            "SELECT COUNT(*) AS count, SUM(amount) AS total
             FROM deposits
             WHERE status = 'completed' AND created_at >= ?",
            [$start]
        );

        $withdrawals = $this->db->fetch(
            "SELECT COUNT(*) AS count, SUM(amount) AS total
             FROM withdrawals
             WHERE status = 'completed' AND created_at >= ?",
            [$start]
        );

        $payments = $this->db->fetch(
            "SELECT COUNT(*) AS count, SUM(amount) AS total
             FROM payments
             WHERE status = 'completed' AND created_at >= ?",
            [$start]
        );

        $platformFee = $this->db->fetch(
            "SELECT SUM(platform_fee) AS total FROM payments
             WHERE status = 'completed' AND created_at >= ?",
            [$start]
        );

        return (object)[
            'deposits' => [
                'count' => (int)($deposits->count ?? 0),
                'amount' => (float)($deposits->total ?? 0),
            ],
            'withdrawals' => [
                'count' => (int)($withdrawals->count ?? 0),
                'amount' => (float)($withdrawals->total ?? 0),
            ],
            'payments' => [
                'count' => (int)($payments->count ?? 0),
                'amount' => (float)($payments->total ?? 0),
            ],
            'platform_fee' => (float)($platformFee->total ?? 0),
        ];
    }

    public function getTransactionVolumeChart(int $days): array
    {
        return $this->db->fetchAll(
            "SELECT DATE(created_at) AS date,
                    SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END) AS deposits,
                    SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END) AS withdrawals,
                    COUNT(*) AS transactions
             FROM (
                SELECT created_at, 'deposit' AS type, amount FROM deposits WHERE status = 'completed'
                UNION ALL
                SELECT created_at, 'withdrawal' AS type, amount FROM withdrawals WHERE status = 'completed'
                UNION ALL
                SELECT created_at, 'payment' AS type, amount FROM payments WHERE status = 'completed'
             ) transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        ) ?? [];
    }

    public function getSocialTaskMetrics(string $start): object
    {
        $ads = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(max_slots) AS total_slots,
                    SUM(reward * max_slots) AS total_budget,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active
             FROM social_ads
             WHERE created_at >= ?",
            [$start]
        );

        $executions = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN decision = 'approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN decision = 'soft_approved' THEN 1 ELSE 0 END) AS soft_approved,
                    SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN decision = 'pending' THEN 1 ELSE 0 END) AS pending,
                    AVG(task_score) AS avg_score,
                    SUM(CASE WHEN decision IN ('approved', 'soft_approved') THEN 1 ELSE 0 END) AS successful
             FROM social_task_executions
             WHERE created_at >= ?",
            [$start]
        );

        $platforms = $this->db->fetchAll(
            "SELECT platform, COUNT(*) AS count, AVG(reward) AS avg_reward
             FROM social_ads
             WHERE created_at >= ?
             GROUP BY platform",
            [$start]
        ) ?? [];

        return (object)[
            'ads' => [
                'total' => (int)($ads->total ?? 0),
                'total_slots' => (int)($ads->total_slots ?? 0),
                'total_budget' => (float)($ads->total_budget ?? 0),
                'active' => (int)($ads->active ?? 0),
            ],
            'executions' => [
                'total' => (int)($executions->total ?? 0),
                'approved' => (int)($executions->approved ?? 0),
                'soft_approved' => (int)($executions->soft_approved ?? 0),
                'rejected' => (int)($executions->rejected ?? 0),
                'pending' => (int)($executions->pending ?? 0),
                'avg_score' => (float)($executions->avg_score ?? 0),
                'successful' => (int)($executions->successful ?? 0),
            ],
            'platforms' => $platforms,
        ];
    }

    public function getRatingMetrics(string $start): object
    {
        $ratings = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    AVG(stars) AS avg_stars,
                    SUM(CASE WHEN stars = 5 THEN 1 ELSE 0 END) AS five_star,
                    SUM(CASE WHEN stars = 4 THEN 1 ELSE 0 END) AS four_star,
                    SUM(CASE WHEN stars = 3 THEN 1 ELSE 0 END) AS three_star,
                    SUM(CASE WHEN stars = 2 THEN 1 ELSE 0 END) AS two_star,
                    SUM(CASE WHEN stars = 1 THEN 1 ELSE 0 END) AS one_star,
                    SUM(status = 'approved') AS approved,
                    SUM(status = 'pending') AS pending,
                    SUM(status = 'rejected') AS rejected
             FROM social_ratings
             WHERE created_at >= ?",
            [$start]
        );

        $topRated = $this->db->fetch(
            "SELECT u.id, u.full_name, AVG(sr.stars) AS avg_rating, COUNT(*) AS rating_count
             FROM social_ratings sr
             JOIN users u ON u.id = sr.rated_id
             WHERE sr.created_at >= ?
             GROUP BY u.id, u.full_name
             ORDER BY avg_rating DESC
             LIMIT 1",
            [$start]
        );

        return (object)[
            'total_ratings' => (int)($ratings->total ?? 0),
            'average_rating' => round((float)($ratings->avg_stars ?? 0), 2),
            'distribution' => [
                '5_star' => (int)($ratings->five_star ?? 0),
                '4_star' => (int)($ratings->four_star ?? 0),
                '3_star' => (int)($ratings->three_star ?? 0),
                '2_star' => (int)($ratings->two_star ?? 0),
                '1_star' => (int)($ratings->one_star ?? 0),
            ],
            'moderation_status' => [
                'approved' => (int)($ratings->approved ?? 0),
                'pending' => (int)($ratings->pending ?? 0),
                'rejected' => (int)($ratings->rejected ?? 0),
            ],
            'top_rated_user' => $topRated ? [
                'id' => $topRated->id,
                'name' => $topRated->full_name,
                'rating' => round((float)$topRated->avg_rating, 2),
                'rating_count' => (int)$topRated->rating_count,
            ] : null,
        ];
    }

    public function getCustomTaskMetrics(string $start): object
    {
        $tasks = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(max_submissions) AS total_submissions,
                    AVG(reward) AS avg_reward,
                    SUM(reward * max_submissions) AS total_budget
             FROM custom_tasks
             WHERE created_at >= ?",
            [$start]
        );

        $submissions = $this->db->fetch(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN decision = 'approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN decision = 'pending' THEN 1 ELSE 0 END) AS pending
             FROM custom_task_submissions
             WHERE created_at >= ?",
            [$start]
        );

        return (object)[
            'tasks' => [
                'total' => (int)($tasks->total ?? 0),
                'active' => (int)($tasks->active ?? 0),
                'total_submissions' => (int)($tasks->total_submissions ?? 0),
                'avg_reward' => (float)($tasks->avg_reward ?? 0),
                'total_budget' => (float)($tasks->total_budget ?? 0),
            ],
            'submissions' => [
                'total' => (int)($submissions->total ?? 0),
                'approved' => (int)($submissions->approved ?? 0),
                'rejected' => (int)($submissions->rejected ?? 0),
                'pending' => (int)($submissions->pending ?? 0),
            ],
        ];
    }

    public function getSystemHealth(): object
    {
        $dbSize = $this->db->fetch(
            "SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()"
        );

        $recentErrors = $this->db->fetchAll(
            "SELECT type, COUNT(*) AS count FROM activity_logs
             WHERE level = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY type
             ORDER BY count DESC
             LIMIT 5"
        ) ?? [];

        $rateLimitHits = $this->db->fetch(
            "SELECT COUNT(*) AS count FROM rate_limits
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND exceeded = 1"
        );

        return (object)[
            'database_size_mb' => (float)($dbSize->size_mb ?? 0),
            'recent_errors' => $recentErrors,
            'rate_limit_hits' => (int)($rateLimitHits->count ?? 0),
        ];
    }

    public function getRevenueBreakdown(string $start): object
    {
        $paymentFees = $this->db->fetch(
            "SELECT SUM(platform_fee) AS total FROM payments
             WHERE status = 'completed' AND created_at >= ?",
            [$start]
        );

        $referralCosts = $this->db->fetch(
            "SELECT SUM(amount) AS total FROM referral_commissions
             WHERE status = 'paid' AND created_at >= ?",
            [$start]
        );

        $withdrawalFees = $this->db->fetch(
            "SELECT SUM(fee) AS total FROM withdrawals
             WHERE status = 'completed' AND created_at >= ?",
            [$start]
        );

        $investmentReturns = $this->db->fetch(
            "SELECT SUM(profit) AS total FROM investments
             WHERE status = 'completed' AND created_at >= ?",
            [$start]
        );

        $totalIncome = (float)($paymentFees->total ?? 0) + (float)($withdrawalFees->total ?? 0);
        $totalExpense = (float)($referralCosts->total ?? 0);

        return (object)[
            'payment_fees' => (float)($paymentFees->total ?? 0),
            'referral_commissions' => (float)($referralCosts->total ?? 0),
            'withdrawal_fees' => (float)($withdrawalFees->total ?? 0),
            'investment_returns' => (float)($investmentReturns->total ?? 0),
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_profit' => $totalIncome - $totalExpense,
        ];
    }
}
