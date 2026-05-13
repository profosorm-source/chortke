<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use Core\Cache;
use App\Services\ReferralAnalyticsService;
use App\Services\Notification\NotificationService;
use App\Models\AdvancedAnalytics;

use App\Contracts\LoggerInterface;
/**
 * AnalyticsService - سرویس اشتراکی تحلیل داده‌ها و آمارها
 * 
 * این سرویس یک unified facade برای تمام آنالیتیکس است:
 * - تحلیل‌های کلی
 * - تحلیل‌های Referral
 * - تحلیل‌های Notification
 * - تحلیل‌های پیشرفته
 * 
 * NOTE: CustomTask analytics moved to App\Services\Analytics\AnalyticsService
 */
class AnalyticsService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        protected LoggerInterface $logger,
        private Cache $cache,
        private ReferralAnalyticsService $referralAnalytics,
        private NotificationService $notificationService,
        private AdvancedAnalytics $advancedAnalytics,
        private \App\Services\Analytics\AnalyticsService $customTaskAnalytics
    ) {
        parent::__construct($logger);
    }

    /**
     * دریافت آمارهای کلی سیستم
     */
    public function getGlobalStats(): array
    {
        return $this->cache->remember('global_stats', 3600, function() {
            return [
                'users_count' => $this->db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
                'active_tasks' => $this->db->query("SELECT COUNT(*) FROM custom_tasks WHERE status = 'active'")->fetchColumn()
            ];
        });
    }

    /**
     * تحلیل روندها (Trends)
     */
    public function getTrends(string $metric, string $period = 'daily'): array
    {
        return [];
    }

    public function getTrend(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30,
        array $conditions = [],
        array $groupByColumns = []
    ): array {
        return [
            'data' => $this->getTrendData($table, $dateColumn, $days, $conditions, $groupByColumns),
        ];
    }

    public function getCount(string $table, array $conditions = []): int
    {
        [$where, $params] = $this->buildWhere($conditions);

        $sql = "SELECT COUNT(*) as total FROM {$table} WHERE {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function getDistribution(
        string $table,
        string $column,
        array $conditions = [],
        int $limit = 10
    ): array {
        return $this->advancedAnalytics->getDistributionData($table, $column, $conditions, $limit);
    }

    public function getAggregates(string $table, array $conditions = [], array $selectColumns = []): array
    {
        $select = empty($selectColumns) ? 'COUNT(*) as total' : implode(', ', $selectColumns);
        [$where, $params] = $this->buildWhere($conditions);

        $sql = "SELECT {$select} FROM {$table} WHERE {$where}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(
            \PDO::FETCH_ASSOC
        );

        return $result ?: [];
    }

    public function exportToCsv(int $adId, int $userId): string
    {
        $sql = "SELECT e.id, e.created_at, e.status, e.payout_amount, e.final_score
                FROM seo_executions e
                INNER JOIN seo_ads a ON e.ad_id = a.id
                WHERE e.ad_id = ? AND a.user_id = ?
                ORDER BY e.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$adId, $userId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $handle = fopen('php://memory', 'rw');
        if ($handle === false) {
            return '';
        }

        $headers = ['id', 'created_at', 'status', 'payout_amount', 'final_score'];
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['created_at'],
                $row['status'],
                $row['payout_amount'],
                $row['final_score'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }

    private function buildWhere(array $conditions): array
    {
        $where = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            if (is_array($value) && count($value) === 2 && strtoupper($value[0]) === 'IN' && is_array($value[1])) {
                if (empty($value[1])) {
                    $where[] = '0=1';
                    continue;
                }

                $placeholders = implode(', ', array_fill(0, count($value[1]), '?'));
                $where[] = "{$column} IN ({$placeholders})";
                $params = array_merge($params, $value[1]);
                continue;
            }

            if ($value === null) {
                $where[] = "{$column} IS NULL";
                continue;
            }

            $where[] = "{$column} = ?";
            $params[] = $value;
        }

        return [empty($where) ? '1=1' : implode(' AND ', $where), $params];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Delegation Methods — هدایت درخواست‌ها به سرویس‌های مختلف
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * تحلیل Custom Task
     */
    public function getCustomTaskStats(): array
    {
        return $this->customTaskAnalytics->getStats();
    }

    /**
     * تحلیل Referral
     */
    public function getReferralStats(): array
    {
        return $this->referralAnalytics->getStats();
    }

    /**
     * تحلیل Notification
     */
    public function getNotificationStats(): array
    {
        return $this->notificationService->getAnalyticsOverview();
    }

    /**
     * تحلیل پیشرفته
     */
    public function getAdvancedStats(): array
    {
        return [];
    }

    /**
     * تحلیل روند (پیشرفته)
     */
    public function getTrendData(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30,
        array $conditions = [],
        array $groupByColumns = []
    ): array {
        return $this->advancedAnalytics->getTrendData($table, $dateColumn, $days, $conditions, $groupByColumns);
    }

    /**
     * مقایسه دو بازه زمانی
     */
    public function comparePeriods(
        string $table,
        string $dateColumn = 'created_at',
        int $currentPeriodDays = 7,
        array $conditions = []
    ): array {
        $currentData = $this->advancedAnalytics->getPeriodStatsData($table, $dateColumn, 0, $currentPeriodDays, $conditions);
        $previousData = $this->advancedAnalytics->getPeriodStatsData($table, $dateColumn, $currentPeriodDays, $currentPeriodDays, $conditions);

        return [
            'current' => $currentData,
            'previous' => $previousData,
            'change_percentage' => $previousData['total'] > 0
                ? round((($currentData['total'] - $previousData['total']) / $previousData['total']) * 100, 2)
                : null,
        ];
    }

    /**
     * نوتیفیکیشن: تحلیل آمار
     */
    public function trackNotification(array $data): void
    {
        // Tracking is now handled directly in NotificationService
        $this->logger->info('notification.analytics.track', $data);
    }

    /**
     * نوتیفیکیشن: دریافت آمار
     */
    public function getNotificationMetrics(): array
    {
        return $this->notificationService->getAnalyticsFunnelStats();
    }
}

