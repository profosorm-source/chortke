<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\User;
use App\Models\KYCVerification;
use App\Models\TransactionQuery;
use App\Models\KpiStatistics;
use App\Models\CustomTaskAnalyticsModel;
use Core\Cache;

use App\Contracts\LoggerInterface;
/**
 * AnalyticsQueryService
 * لایه Query برای تمام داده‌های تحلیلی
 * M-SRV-04 Fix: بازآرایی معماری - تبدیل نام گمراه‌کننده AnalyticsDataRepository به سرویس کوئری اختصاصی
 */
class AnalyticsQueryService
{
    // MED-10: TTLهای متفاوت براساس نوع داده
    private const CACHE_TTL_HOT = 300;      // Real-time: 5 دقیقه (آمار لحظه‌ای)
    private const CACHE_TTL_WARM = 3600;    // Daily: 1 ساعت (آمار روزانه)
    private const CACHE_TTL_COLD = 86400;   // Historical: 24 ساعت (آمار تاریخی)

    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private KpiStatistics $kpiStats;
    private CustomTaskAnalyticsModel $customTaskAnalyticsModel;
    private User $userModel;
    private KYCVerification $kycModel;
    private TransactionQuery $transactionQuery;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        KpiStatistics $kpiStats,
        CustomTaskAnalyticsModel $customTaskAnalyticsModel,
        User $userModel,
        KYCVerification $kycModel,
        TransactionQuery $transactionQuery
    ) {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;
        $this->kpiStats = $kpiStats;
        $this->customTaskAnalyticsModel = $customTaskAnalyticsModel;
        $this->userModel = $userModel;
        $this->kycModel = $kycModel;
        $this->transactionQuery = $transactionQuery;

        
    }

    // ==========================================
    //  آمار کاربران
    // ==========================================

    /**
     * آمار کاربران جامع (همراه با کشینگ هوشمند) - WARM: 1 ساعت
     */
    public function getUserStats(): array
    {
        $cacheKey = 'user_comprehensive_stats';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_WARM, function() {
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
        return $this->cache->remember("financial_stats_{$curr}", self::CACHE_TTL_HOT, fn() => $this->transactionQuery->getFinancialStats($curr));
    }

    // ==========================================
    //  آمار تسک‌ها
    // ==========================================

    /**
     * آمار تسک‌ها - HOT: 5 دقیقه
     */
    public function getTaskStats(): array
    {
        $cacheKey = 'task_stats';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getTaskStats());
    }

    public function getTicketStats(): array
    {
        $cacheKey = 'ticket_stats';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_WARM, fn() => $this->kpiStats->getTicketStats());
    }

    public function getFraudStats(): array
    {
        $cacheKey = 'fraud_stats';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getFraudStats());
    }

    public function getChurnRate(): float
    {
        $cacheKey = 'churn_rate';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_WARM, fn() => $this->kpiStats->getChurnRate());
    }

    public function getConversionRate(): float
    {
        $cacheKey = 'conversion_rate';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_WARM, fn() => $this->kpiStats->getConversionRate());
    }

    public function getTasksByPlatform(): array
    {
        $cacheKey = 'tasks_by_platform';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getTasksByPlatform());
    }

    public function getHourlyActivity(int $days = 30): array
    {
        $cacheKey = "hourly_activity_{$days}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getHourlyActivity($days));
    }

    public function getInvestmentStats(): array
    {
        $cacheKey = 'investment_stats';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_WARM, fn() => $this->kpiStats->getInvestmentStats());
    }

    public function getReferralStats(): array
    {
        $cacheKey = 'referral_stats';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_WARM, fn() => $this->kpiStats->getReferralStats());
    }

    public function getTopUsers(int $limit = 20): array
    {
        $cacheKey = "top_users_{$limit}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_WARM, fn() => $this->kpiStats->getTopUsers($limit));
    }

    public function getLotteryStats(): array
    {
        $cacheKey = 'lottery_stats';
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getLotteryStats());
    }

    /**
     * خلاصه داشبورد با قابلیت Lazy Loading سکشن‌های خاص (جهت جلوگیری از سربار سنگین)
     */
    public function getDashboardSummary(array $sections = []): array
    {
        $all = ['users', 'financial', 'task', 'ticket', 'fraud', 'lottery', 'referral', 'investment'];
        $sections = $sections ?: $all;
        
        $summary = [];
        foreach ($sections as $s) {
            // مپ کردن نام سکشن به متد مناسب
            $method = 'get' . ucfirst($s) . 'Stats';
            if (method_exists($this, $method)) {
                 // نام نمایشی را نرمال می‌کنیم (مثل taskStats -> tasks)
                 $key = str_ends_with($s, 's') ? $s : $s . 's'; 
                 $summary[$key] = $this->{$method}();
            }
        }
        return $summary;
    }

    // ==========================================
    //  آمار Custom Tasks (از CustomTaskModel)
    // ==========================================

    /**
     * دریافت آمار کامل یک تسک سفارشی - HOT: 5 دقیقه
     */
    public function getCustomTaskStats(int $taskId, int $days = 30): array
    {
        $cacheKey = "task_stats_{$taskId}_{$days}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, function () use ($taskId, $days) {
            return $this->customTaskAnalyticsModel->analytics_getTaskStats($taskId, $days);
        });
    }

    /**
     * دریافت آمار داشبورد creator - HOT: 5 دقیقه
     */
    public function getCreatorDashboard(int $userId): array
    {
        $cacheKey = "creator_dashboard_{$userId}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, function () use ($userId) {
            return $this->customTaskAnalyticsModel->analytics_getCreatorDashboard($userId);
        });
    }

    /**
     * دریافت آمار داشبورد worker - HOT: 5 دقیقه
     */
    public function getWorkerDashboard(int $userId): array
    {
        $cacheKey = "worker_dashboard_{$userId}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, function () use ($userId) {
            return $this->customTaskAnalyticsModel->analytics_getWorkerDashboard($userId);
        });
    }

    /**
     * تسک‌های محبوب - WARM: 30 دقیقه
     */
    public function getTrendingTasks(int $limit = 10): array
    {
        $cacheKey = "trending_tasks_{$limit}";
        return $this->cache->remember($cacheKey, 1800, function () use ($limit) {
            return $this->customTaskAnalyticsModel->analytics_getTrendingTasks($limit);
        });
    }

    // ==========================================
    //  داده‌های زمانی (برای نمودارها)
    // ==========================================

    /**
     * ثبت‌نام روزانه - HOT: 5 دقیقه
     */
    public function getDailyRegistrations(int $days = 30): array
    {
        $cacheKey = "daily_registrations_{$days}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getDailyRegistrations($days));
    }

    /**
     * درآمد روزانه - HOT: 5 دقیقه
     */
    public function getDailyRevenue(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $cacheKey = "daily_revenue_{$days}_{$curr}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getDailyRevenue($days, $curr));
    }

    /**
     * واریز و برداشت روزانه - HOT: 5 دقیقه
     */
    public function getDailyDepositsWithdrawals(int $days = 30, ?string $currency = null): array
    {
        $curr = strtoupper($currency ?: 'IRT');
        $cacheKey = "daily_deposits_withdrawals_{$days}_{$curr}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getDailyDepositsWithdrawals($days, $curr));
    }

    /**
     * تسک‌های تکمیل‌شده روزانه - HOT: 5 دقیقه
     */
    public function getDailyCompletedTasks(int $days = 30): array
    {
        $cacheKey = "daily_completed_tasks_{$days}";
        return $this->cache->remember($cacheKey, self::CACHE_TTL_HOT, fn() => $this->kpiStats->getDailyCompletedTasks($days));
    }

    /**
     * بررسی جامع سلامت سیستم (Health Check V2)
     */
    public function getSystemHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => []
        ];

        // 1. Database Check
        try {
            $this->db->query("SELECT 1");
            $health['checks']['database'] = ['status' => 'ok'];
        } catch (\Throwable $e) {
            $health['checks']['database'] = ['status' => 'fail', 'message' => $e->getMessage()];
            $health['status'] = 'unhealthy';
        }

        // 2. Cache/Redis Check
        try {
            $this->cache->set('health_check', 1, 10);
            if ($this->cache->get('health_check') == 1) {
                $health['checks']['cache'] = ['status' => 'ok'];
            } else {
                throw new \Exception('Cache value mismatch');
            }
        } catch (\Throwable $e) {
            $health['checks']['cache'] = ['status' => 'fail', 'message' => $e->getMessage()];
            $health['status'] = 'warning';
        }

        // 3. Storage Check
        $storagePath = BASE_PATH . '/storage';
        if (is_writable($storagePath)) {
            $freeSpace = disk_free_space($storagePath);
            $totalSpace = disk_total_space($storagePath);
            $usagePercent = round(100 - ($freeSpace / $totalSpace * 100), 2);
            
            $health['checks']['storage'] = [
                'status' => $usagePercent > 90 ? 'warning' : 'ok',
                'usage' => $usagePercent . '%',
                'free' => round($freeSpace / 1024 / 1024 / 1024, 2) . ' GB'
            ];
            if ($usagePercent > 95) $health['status'] = 'warning';
        } else {
            $health['checks']['storage'] = ['status' => 'fail', 'message' => 'Storage not writable'];
            $health['status'] = 'unhealthy';
        }

        // 4. Logs Check
        $logPath = $storagePath . '/logs/system.log';
        if (file_exists($logPath) && filesize($logPath) > 100 * 1024 * 1024) { // 100MB
            $health['checks']['logs'] = ['status' => 'warning', 'message' => 'Log file too large'];
        } else {
            $health['checks']['logs'] = ['status' => 'ok'];
        }

        return $health;
    }

    /**
     * باطل کردن کش به صورت Event-based (جایگزین پاک کردن زمانی صرف)
     * @param string $category نوع دسته‌بندی کش (user, task, financial, all)
     * @param int|null $contextId شناسه متنی (مانند userId یا taskId)
     */
    public function clearCache(string $category = 'all', ?int $contextId = null): void
    {
        switch ($category) {
            case 'user':
                $this->cache->delete('user_comprehensive_stats');
                $this->cache->delete('churn_rate');
                $this->cache->delete('conversion_rate');
                $this->cache->delete("top_users_20");
                if ($contextId) {
                    $this->cache->delete("creator_dashboard_{$contextId}");
                    $this->cache->delete("worker_dashboard_{$contextId}");
                }
                break;

            case 'task':
                $this->cache->delete('task_stats');
                $this->cache->delete('tasks_by_platform');
                $this->cache->delete('trending_tasks_10');
                if ($contextId) {
                    $this->cache->delete("task_stats_{$contextId}_30");
                    $this->cache->delete("task_stats_{$contextId}_7");
                }
                break;

            case 'financial':
                // اگر متد getFinancialStats کش شود اینجا باید خالی شود.
                $this->cache->delete('financial_stats_irt');
                $this->cache->delete('financial_stats_usdt');
                $this->cache->delete('investment_stats');
                $this->cache->delete('referral_stats');
                // پاک کردن کش نمودارهای درآمد روزانه
                $this->cache->delete("daily_revenue_30_IRT");
                $this->cache->delete("daily_deposits_withdrawals_30_IRT");
                break;
            
            case 'ticket':
                $this->cache->delete('ticket_stats');
                break;

            case 'fraud':
                $this->cache->delete('fraud_stats');
                break;

            case 'all':
            default:
                $keys = [
                    'user_comprehensive_stats', 'task_stats', 'ticket_stats',
                    'fraud_stats', 'churn_rate', 'conversion_rate',
                    'tasks_by_platform', 'investment_stats', 'referral_stats',
                    'lottery_stats', 'trending_tasks_10', 'top_users_20'
                ];
                foreach ($keys as $key) {
                    $this->cache->delete($key);
                }
                break;
        }
        
        $this->logger->info('analytics.cache.cleared', [
            'category' => $category,
            'context_id' => $contextId
        ]);
    }
}
