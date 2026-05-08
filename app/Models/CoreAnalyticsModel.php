<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * CoreAnalyticsModel — تحلیل‌های کسب‌وکار، نمودارهای مالی، رشد پلتفرم و شاخص‌های کلیدی عملکرد (KPIs)
 */
class CoreAnalyticsModel extends Model
{
    protected static string $table = 'activity_logs';

    public function getUserMetrics(string $start): object
    {
        $totalUsers = $this->db->fetch("SELECT COUNT(*) AS total FROM users");
        
        $activeUsers = $this->db->table('activity_logs')
            ->where('created_at', '>=', $start)
            ->distinct()
            ->count('user_id');

        $newUsers = $this->db->table('users')
            ->where('created_at', '>=', $start)
            ->count();

        $usersByLevel = $this->db->table('users as u')
            ->select('u.level_id', 'ul.level_name', 'COUNT(*) AS count')
            ->leftJoin('user_levels as ul', 'u.level_id', '=', 'ul.id')
            ->groupBy('u.level_id', 'ul.level_name')
            ->orderBy('count', 'DESC')
            ->get();

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
            'active_users' => (int)$activeUsers,
            'new_users' => (int)$newUsers,
            'users_by_level' => $usersByLevel ?? [],
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
        $deposits = $this->db->table('deposits')
            ->select('COUNT(*) AS count', 'SUM(amount) AS total')
            ->where('status', '=', 'completed')
            ->where('created_at', '>=', $start)
            ->first();

        $withdrawals = $this->db->table('withdrawals')
            ->select('COUNT(*) AS count', 'SUM(amount) AS total')
            ->where('status', '=', 'completed')
            ->where('created_at', '>=', $start)
            ->first();

        $payments = $this->db->table('payments')
            ->select('COUNT(*) AS count', 'SUM(amount) AS total')
            ->where('status', '=', 'completed')
            ->where('created_at', '>=', $start)
            ->first();

        $platformFee = $this->db->table('payments')
            ->where('status', '=', 'completed')
            ->where('created_at', '>=', $start)
            ->sum('platform_fee');

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
            'platform_fee' => (float)$platformFee,
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

    public function getRevenueBreakdown(string $start): object
    {
        $paymentFees = $this->db->table('payments')
            ->where('status', '=', 'completed')
            ->where('created_at', '>=', $start)
            ->sum('platform_fee');

        $referralCosts = $this->db->table('referral_commissions')
            ->where('status', '=', 'paid')
            ->where('created_at', '>=', $start)
            ->sum('amount');

        $withdrawalFees = $this->db->table('withdrawals')
            ->where('status', '=', 'completed')
            ->where('created_at', '>=', $start)
            ->sum('fee');

        $investmentReturns = $this->db->table('investments')
            ->where('status', '=', 'completed')
            ->where('created_at', '>=', $start)
            ->sum('profit');

        $totalIncome = (float)$paymentFees + (float)$withdrawalFees;
        $totalExpense = (float)$referralCosts;

        return (object)[
            'payment_fees' => (float)$paymentFees,
            'referral_commissions' => (float)$referralCosts,
            'withdrawal_fees' => (float)$withdrawalFees,
            'investment_returns' => (float)$investmentReturns,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_profit' => $totalIncome - $totalExpense,
        ];
    }
}
