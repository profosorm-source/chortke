<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\Cache;
use App\Contracts\LoggerInterface;

class NotificationAnalyticsService
{
    private const ANALYTICS_CACHE_PREFIX = 'notif_analytics:';
    private const ANALYTICS_CACHE_TTL = 15;

    private \Core\Cache $cache;
    private Notification $notificationModel;
    public function __construct(
        \Core\Cache $cache,
        Notification $notificationModel
    ) {        $this->cache = $cache;
        $this->notificationModel = $notificationModel;

        
    }

    public function getAnalyticsOverview(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "overview:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getOverviewStats($days) ?: []
        );
    }

    public function getAnalyticsByType(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "by_type:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getAdminStatsByType($days) ?: []
        );
    }

    public function getAnalyticsDailyTrend(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "daily:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getDailyStats($days) ?: []
        );
    }

    public function getAnalyticsSegmentStats(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "segment:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getStatsBySegment($days) ?: []
        );
    }

    public function getAnalyticsFunnelStats(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "funnel:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getFunnelStats($days) ?: []
        );
    }

    public function getAnalyticsFatigueReport(int $threshold = 20): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "fatigue:{$threshold}",
            self::ANALYTICS_CACHE_TTL,
            function () use ($threshold) {
                $users = $this->notificationModel->getHighUnreadUsers($threshold, 50);
                $summary = $this->notificationModel->getFatigueSummary($threshold);

                return [
                    'summary' => $summary ?: [],
                    'users'   => $users ?: [],
                ];
            }
        );
    }
}
