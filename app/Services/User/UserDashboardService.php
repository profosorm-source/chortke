<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;
use App\Contracts\LoggerInterface;

use Core\Cache;

class UserDashboardService extends \App\Services\BaseService
{
    private Database $db;
    private ?Cache $cache;

    public function __construct(Database $db, LoggerInterface $logger, ?Cache $cache = null)
    {
        parent::__construct($logger);
        $this->db = $db;
        $this->cache = $cache;
    }

    public function getStats(int $userId): array
    {
        $cacheKey = "user_dashboard_stats:{$userId}";
        if ($this->cache && ($cached = $this->cache->get($cacheKey))) {
            return $cached;
        }

        // Optimized: Consolidated transaction statistics into a single query
        $todayStart = \date('Y-m-d 00:00:00');
        $todayEnd   = \date('Y-m-d 00:00:00', \strtotime('+1 day'));

        $summary = $this->db->fetch("
            SELECT 
                SUM(CASE WHEN type = 'deposit' AND status = 'completed' AND created_at >= :start AND created_at < :end THEN amount ELSE 0 END) as today_deposit,
                SUM(CASE WHEN type = 'withdraw' AND status = 'completed' AND created_at >= :start AND created_at < :end THEN amount ELSE 0 END) as today_withdraw,
                COUNT(CASE WHEN status IN ('pending', 'processing') THEN 1 END) as pending_tx,
                SUM(CASE WHEN type IN ('task_reward', 'commission') AND status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as earnings_30d
            FROM transactions 
            WHERE user_id = :uid",
            ['uid' => $userId, 'start' => $todayStart, 'end' => $todayEnd]
        );

        $lastTransactions = $this->db->fetchAll(
            "SELECT id, type, currency, amount, status, created_at
             FROM transactions
             WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT 10",
            ['uid' => $userId]
        );

        $stats = [
            'today_deposit'      => (float)($summary->today_deposit ?? 0),
            'today_withdraw'     => (float)($summary->today_withdraw ?? 0),
            'pending_tx'         => (int)($summary->pending_tx ?? 0),
            'earnings_30d'       => (float)($summary->earnings_30d ?? 0),
            'last_transactions'  => $lastTransactions,
        ];

        if ($this->cache) {
            $this->cache->set($cacheKey, $stats, 60);
        }

        return $stats;
    }

    /**
     * Get all dashboard data in a single call to minimize round-trips
     */
    public function getFullDashboardData(int $userId): array
    {
        return [
            'stats' => $this->getStats($userId),
            'recent_executions' => $this->getRecentTaskExecutions($userId, 5),
            'ticket_count' => $this->getOpenTicketCount($userId)
        ];
    }

    public function getRecentTaskExecutions(int $userId, int $limit = 5, int $offset = 0): array
    {
        $safeLimit = max(1, min(100, $limit));
        $safeOffset = max(0, $offset);

        // MED-03: Join against social_ads to surface critical visual metadata pointers to layout views
        return $this->db->fetchAll(
            "SELECT ste.*, sa.title AS ad_title, sa.platform AS ad_platform, sa.task_type AS ad_task_type
             FROM social_task_executions ste
             LEFT JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.executor_id = :uid
             ORDER BY ste.created_at DESC
             LIMIT :limit OFFSET :offset",
            [
                'uid' => $userId,
                'limit' => $safeLimit,
                'offset' => $safeOffset,
            ]
        );
    }

    public function getOpenTicketCount(int $userId): int
    {
        $cacheKey = "user_open_tickets:{$userId}";
        if ($this->cache && ($cachedCount = $this->cache->get($cacheKey)) !== false) {
            return (int)$cachedCount;
        }

        $count = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE user_id = :uid AND status IN ('open', 'pending')",
            ['uid' => $userId]
        );

        // LOW-03: Cache open tickets count to lower synchronous execution impacts on shell load
        if ($this->cache) {
            $this->cache->set($cacheKey, $count, 60);
        }

        return $count;
    }
}
