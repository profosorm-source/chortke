<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * KpiStatistics Model — KPI Dashboard Data Access Layer
 * 
 * مسئولیت: محاسبه KPI های سیستم و داشبورد کلی.
 * استفاده می‌شود در: AnalyticsQueryService
 */
class KpiStatistics extends Model
{
    public function __construct(\Core\Database $db)
    {
        parent::__construct($db);
    }

    /**
     * نرخ رشد کاربران ماهانه
     */
    public function getMonthlyUserGrowth(int $months = 12): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as new_users
             FROM users
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month DESC"
        );
        $stmt->execute([$months]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * نرخ تحقق KYC
     */
    public function getKycCompletionRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                SUM(CASE WHEN kyc_status = 'verified' THEN 1 ELSE 0 END) / COUNT(*) * 100 as rate
             FROM users
             WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 YEAR)"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * میانگین مدت زمان تحقق KYC (ساعت)
     */
    public function getAverageKycVerificationTime(): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours
             FROM kyc_verification
             WHERE status = 'verified'"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * نسبت فعالیت کاربران
     */
    public function getUserActivityRatio(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(DISTINCT user_id) / (SELECT COUNT(*) FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)) * 100 as ratio
             FROM activity_logs
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * متوسط تراکنش به ازای هر کاربر
     */
    public function getAverageTransactionPerUser(): float
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) / (SELECT COUNT(DISTINCT user_id) FROM transactions) as avg
             FROM transactions"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * آمار تسک‌ها
     */
    public function getTaskStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' AND DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END) as completed_today,
                SUM(CASE WHEN status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as completed_week,
                SUM(CASE WHEN status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as completed_month,
                SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as pending_verification,
                SUM(CASE WHEN status = 'fraud_detected' THEN 1 ELSE 0 END) as fraud_detected
             FROM tasks WHERE deleted_at IS NULL"
        );
        $stmt->execute();
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $platforms = $this->db->prepare(
            "SELECT platform, COUNT(*) as count FROM tasks WHERE deleted_at IS NULL GROUP BY platform ORDER BY count DESC"
        );
        $platforms->execute();
        $stats['by_platform'] = $platforms->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $types = $this->db->prepare(
            "SELECT type, COUNT(*) as count FROM tasks WHERE deleted_at IS NULL GROUP BY type ORDER BY count DESC"
        );
        $types->execute();
        $stats['by_type'] = $types->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        return $stats;
    }

    /**
     * آمار تیکت‌ها
     */
    public function getTicketStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                SUM(CASE WHEN status IN ('open','pending') THEN 1 ELSE 0 END) as open,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) as waiting
             FROM tickets"
        );
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمار کلاهبرداری
     */
    public function getFraudStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                SUM(CASE WHEN f.status IN ('open','pending') THEN 1 ELSE 0 END) as reports,
                SUM(CASE WHEN t.status = 'fraud_detected' THEN 1 ELSE 0 END) as detected
             FROM fraud_reports f
             LEFT JOIN tasks t ON 1=1"
        );
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * نسبت تسک‌های تکمیل شده
     */
    public function getTaskCompletionRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) / COUNT(*) * 100 as rate
             FROM custom_tasks"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * میانگین زمان تسک (ساعت)
     */
    public function getAverageTaskDuration(): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours
             FROM custom_tasks
             WHERE status IN ('completed', 'approved')"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * نسبت تغییر کاربران (Churn Rate)
     */
    public function getChurnRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                (SUM(CASE WHEN status IN (2,3) OR deleted_at IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*) * 100) as rate
             FROM users"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * نسبت تبدیل (Conversion Rate)
     */
    public function getConversionRate(): float
    {
        $stmt = $this->db->prepare(
            "SELECT 
                (COUNT(CASE WHEN te.status = 'completed' THEN 1 END) / COUNT(DISTINCT t.id) * 100) as rate
             FROM tasks t
             LEFT JOIN task_executions te ON t.id = te.task_id
             WHERE t.deleted_at IS NULL"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * تسک‌ها بر اساس پلتفرم
     */
    public function getTasksByPlatform(): array
    {
        $stmt = $this->db->prepare(
            "SELECT platform, COUNT(*) as count 
             FROM tasks WHERE deleted_at IS NULL 
             GROUP BY platform ORDER BY count DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * فعالیت ساعتی
     */
    public function getHourlyActivity(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT HOUR(created_at) as hour, COUNT(*) as count
             FROM task_executions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY HOUR(created_at)
             ORDER BY hour ASC"
        );
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $result = array_fill(0, 24, 0);
        foreach ($rows as $row) {
            $hour = (int)$row['hour'];
            $result[$hour] = (int)$row['count'];
        }

        return $result;
    }

    /**
     * آمار سرمایه‌گذاری
     */
    public function getInvestmentStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COALESCE(SUM(amount), 0) as total_investment,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'matured' THEN 1 ELSE 0 END) as matured,
                COALESCE(SUM(profit), 0) as total_profit
             FROM investments WHERE deleted_at IS NULL"
        );
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمار ارجاع (Referral Stats)
     */
    public function getReferralStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(DISTINCT user_id) as total_referrals,
                COALESCE(SUM(amount), 0) as total_commission
             FROM (
                SELECT user_id, NULL as amount FROM users WHERE referred_by IS NOT NULL
                UNION ALL
                SELECT NULL, amount FROM referral_commissions
             ) t"
        );
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * کاربران برتر
     */
    public function getTopUsers(int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.name, 
                    COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.amount ELSE 0 END), 0) as total_amount
             FROM users u
             LEFT JOIN transactions t ON t.user_id = u.id
             GROUP BY u.id
             ORDER BY total_amount DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمار لاتاری
     */
    public function getLotteryStats(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                SUM(CASE WHEN lr.deleted_at = 0 THEN 1 ELSE 0 END) as total_rounds,
                SUM(CASE WHEN lr.status = 'active' AND lr.deleted_at = 0 THEN 1 ELSE 0 END) as active_rounds,
                SUM(CASE WHEN lp.is_deleted = 0 THEN 1 ELSE 0 END) as participations
             FROM lottery_rounds lr
             LEFT JOIN lottery_participations lp ON lr.id = lp.round_id"
        );
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * ثبت‌نام روزانه
     */
    public function getDailyRegistrations(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND deleted_at IS NULL
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * درآمد روزانه
     */
    public function getDailyRevenue(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions 
             WHERE type IN ('commission_site','tax','fee') AND status = 'completed' AND currency = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $stmt->execute([$curr, $days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * واریز و برداشت روزانه
     */
    public function getDailyDepositsWithdrawals(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $deposits = $this->db->prepare(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions WHERE type = 'deposit' AND status = 'completed' AND currency = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $deposits->execute([$curr, $days]);

        $withdrawals = $this->db->prepare(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions WHERE type = 'withdraw' AND status = 'completed' AND currency = ?
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC"
        );
        $withdrawals->execute([$curr, $days]);

        return [
            'deposits' => $deposits->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            'withdrawals' => $withdrawals->fetchAll(\PDO::FETCH_ASSOC) ?: []
        ];
    }

    /**
     * تسک‌های تکمیل‌شده روزانه
     */
    public function getDailyCompletedTasks(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(completed_at) as date, COUNT(*) as count 
             FROM task_executions WHERE status = 'completed'
             AND completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(completed_at) ORDER BY date ASC"
        );
        $stmt->execute([$days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * دریافت KPI های کلیدی
     */
    public function getKeyKpis(): array
    {
        return [
            'kyc_completion_rate' => $this->getKycCompletionRate(),
            'user_activity_ratio' => $this->getUserActivityRatio(),
            'task_completion_rate' => $this->getTaskCompletionRate(),
            'churn_rate' => $this->getChurnRate(),
            'conversion_rate' => $this->getConversionRate(),
            'monthly_user_growth' => $this->getMonthlyUserGrowth(12),
        ];
    }

    /**
     * داشبورد خلاصه
     */
    public function getDashboardSummary(): array
    {
        return [
            'key_kpis' => $this->getKeyKpis(),
            'kyc_completion_rate' => $this->getKycCompletionRate(),
            'avg_kyc_time_hours' => $this->getAverageKycVerificationTime(),
            'avg_transaction_per_user' => $this->getAverageTransactionPerUser(),
            'avg_task_duration' => $this->getAverageTaskDuration(),
            'churn_rate' => $this->getChurnRate(),
            'conversion_rate' => $this->getConversionRate(),
        ];
    }
}
