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

        // MED-02: Replace index-breaking DATE(created_at) function with optimized range filters
        $todayStart = \date('Y-m-d 00:00:00');
        $todayEnd   = \date('Y-m-d 00:00:00', \strtotime('+1 day'));

        $todayDeposit = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE user_id = :uid AND type = 'deposit' AND status = 'completed' 
               AND created_at >= :start AND created_at < :end",
            ['uid' => $userId, 'start' => $todayStart, 'end' => $todayEnd]
        );

        $todayWithdraw = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE user_id = :uid AND type = 'withdraw' AND status = 'completed' 
               AND created_at >= :start AND created_at < :end",
            ['uid' => $userId, 'start' => $todayStart, 'end' => $todayEnd]
        );

        $pendingTx = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions 
             WHERE user_id = :uid AND status IN ('pending', 'processing')",
            ['uid' => $userId]
        );

        $totalEarningsMonth = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE user_id = :uid AND type IN ('task_reward', 'commission') AND status = 'completed'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
             ['uid' => $userId]
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
            'today_deposit'      => $todayDeposit,
            'today_withdraw'     => $todayWithdraw,
            'pending_tx'         => $pendingTx,
            'earnings_30d'       => $totalEarningsMonth,
            'last_transactions'  => $lastTransactions,
        ];

        // HIGH-01: Cache dashboard analytical aggregator for a light 60s duration
        if ($this->cache) {
            $this->cache->set($cacheKey, $stats, 60);
        }

        return $stats;
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
