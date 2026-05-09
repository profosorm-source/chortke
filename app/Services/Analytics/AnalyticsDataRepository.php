<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\User;
use App\Models\KYCVerification;
use App\Models\Transaction;
use App\Models\KpiStatistics;
use App\Models\CustomTaskModel;
use Core\Cache;

use App\Contracts\LoggerInterface;
/**
 * AnalyticsDataRepository
 * لایه Query برای تمام داده‌های تحلیلی
 * REFACTORED: نه مستقیم SQL، بلکه از Statistics Models استفاده می‌کند
 */
class AnalyticsDataRepository extends \App\Services\BaseService
{
    private int $cacheTtl = 5; // دقیقه

    public function __construct(
        private KpiStatistics $kpiStats,
        private Cache $cache,
        private CustomTaskModel $customTaskModel,
        private User $userModel,
        private KYCVerification $kycModel,
        private Transaction $transactionModel,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    // ==========================================
    //  آمار کاربران
    // ==========================================

    /**
     * آمار کاربران جامع (همراه با کشینگ هوشمند)
     */
    public function getUserStats(): array
    {
        $cacheKey = 'user_comprehensive_stats';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, function() {
            $countStats = $this->userModel->getUserCountStats();
            $newUserStats = $this->userModel->getNewUserStats();
            $activityStats = $this->userModel->getUserActivityStats();
            $tierStats = $this->userModel->getUserTierStats();
            $kycStats = $this->kycModel->getKycStats();

            return [
                'total' => $countStats['total'],
                'active' => $countStats['active'],
                'banned' => $countStats['banned'],
                'suspended' => $countStats['suspended'],
                'new_today' => $newUserStats['new_today'],
                'new_this_week' => $newUserStats['new_this_week'],
                'new_this_month' => $newUserStats['new_this_month'],
                'dau' => $activityStats['dau'],
                'wau' => $activityStats['wau'],
                'mau' => $activityStats['mau'],
                'tiers' => $tierStats,
                'kyc_verified' => $kycStats['verified'],
                'kyc_pending' => $kycStats['pending'],
            ];
        });
    }

    // ==========================================
    //  آمار مالی
    // ==========================================

    /**
     * آمار مالی
     */
    public function getFinancialStats(?string $currency = null): array
    {
        $curr = strtolower($currency ?: 'irt');
        return $this->transactionModel->getFinancialStats($curr);
    }

    // ==========================================
    //  آمار تسک‌ها
    // ==========================================

    /**
     * آمار تسک‌ها
     */
    public function getTaskStats(): array
    {
        $cacheKey = 'task_stats';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getTaskStats());
    }

    public function getTicketStats(): array
    {
        $cacheKey = 'ticket_stats';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getTicketStats());
    }

    public function getFraudStats(): array
    {
        $cacheKey = 'fraud_stats';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getFraudStats());
    }

    public function getChurnRate(): float
    {
        $cacheKey = 'churn_rate';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getChurnRate());
    }

    public function getConversionRate(): float
    {
        $cacheKey = 'conversion_rate';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getConversionRate());
    }

    public function getTasksByPlatform(): array
    {
        $cacheKey = 'tasks_by_platform';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getTasksByPlatform());
    }

    public function getHourlyActivity(int $days = 30): array
    {
        $cacheKey = "hourly_activity_{$days}";
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getHourlyActivity($days));
    }

    public function getInvestmentStats(): array
    {
        $cacheKey = 'investment_stats';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getInvestmentStats());
    }

    public function getReferralStats(): array
    {
        $cacheKey = 'referral_stats';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getReferralStats());
    }

    public function getTopUsers(int $limit = 20): array
    {
        $cacheKey = "top_users_{$limit}";
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getTopUsers($limit));
    }

    public function getLotteryStats(): array
    {
        $cacheKey = 'lottery_stats';
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getLotteryStats());
    }

    public function getDashboardSummary(): array
    {
        return [
            'users' => $this->getUserStats(),
            'financial' => $this->getFinancialStats(),
            'tasks' => $this->getTaskStats(),
            'tickets' => $this->getTicketStats(),
            'fraud' => $this->getFraudStats(),
            'lottery' => $this->getLotteryStats(),
            'referral' => $this->getReferralStats(),
            'investment' => $this->getInvestmentStats(),
        ];
    }

    // ==========================================
    //  آمار Custom Tasks (از CustomTaskModel)
    // ==========================================

    /**
     * دریافت آمار کامل یک تسک سفارشی
     */
    public function getCustomTaskStats(int $taskId, int $days = 30): array
    {
        $cacheKey = "task_stats_{$taskId}_{$days}";
        return $this->cache->remember($cacheKey, 300, function () use ($taskId, $days) {
            return $this->customTaskModel->analytics_getTaskStats($taskId, $days);
        });
    }

    /**
     * دریافت آمار داشبورد creator
     */
    public function getCreatorDashboard(int $userId): array
    {
        $cacheKey = "creator_dashboard_{$userId}";
        return $this->cache->remember($cacheKey, 600, function () use ($userId) {
            return $this->customTaskModel->analytics_getCreatorDashboard($userId);
        });
    }

    /**
     * دریافت آمار داشبورد worker
     */
    public function getWorkerDashboard(int $userId): array
    {
        $cacheKey = "worker_dashboard_{$userId}";
        return $this->cache->remember($cacheKey, 600, function () use ($userId) {
            return $this->customTaskModel->analytics_getWorkerDashboard($userId);
        });
    }

    /**
     * تسک‌های محبوب
     */
    public function getTrendingTasks(int $limit = 10): array
    {
        $cacheKey = "trending_tasks_{$limit}";
        return $this->cache->remember($cacheKey, 1800, function () use ($limit) {
            return $this->customTaskModel->analytics_getTrendingTasks($limit);
        });
    }

    // ==========================================
    //  داده‌های زمانی (برای نمودارها)
    // ==========================================

    /**
     * ثبت‌نام روزانه
     */
    public function getDailyRegistrations(int $days = 30): array
    {
        $cacheKey = "daily_registrations_{$days}";
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getDailyRegistrations($days));
    }

    /**
     * درآمد روزانه
     */
    public function getDailyRevenue(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $cacheKey = "daily_revenue_{$days}_{$curr}";
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getDailyRevenue($days, $curr));
    }

    /**
     * واریز و برداشت روزانه
     */
    public function getDailyDepositsWithdrawals(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $cacheKey = "daily_deposits_withdrawals_{$days}_{$curr}";
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getDailyDepositsWithdrawals($days, $curr));
    }

    /**
     * تسک‌های تکمیل‌شده روزانه
     */
    public function getDailyCompletedTasks(int $days = 30): array
    {
        $cacheKey = "daily_completed_tasks_{$days}";
        return $this->cache->remember($cacheKey, $this->cacheTtl * 60, fn() => $this->kpiStats->getDailyCompletedTasks($days));
    }

    /**
     * پاک کردن کش
     */
    public function clearCache(int $taskId = null, int $userId = null): void
    {
        if ($taskId) {
            $this->cache->delete("task_stats_{$taskId}_30");
            $this->cache->delete("task_stats_{$taskId}_7");
        }

        if ($userId) {
            $this->cache->delete("creator_dashboard_{$userId}");
            $this->cache->delete("worker_dashboard_{$userId}");
        }

        $this->cache->delete("trending_tasks_10");
    }
}
