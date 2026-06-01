<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;
use App\Contracts\LoggerInterface;

use Core\Cache;

class UserDashboardService
{



    private \Core\Cache $cache;
    private \Core\Database $db;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db
    )
    {        $this->cache = $cache;
        $this->db = $db;

        
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

        // 1. Transactions Summary
        $txSummary = $this->db->fetch("
            SELECT 
                SUM(CASE WHEN type = 'deposit' AND status = 'completed' AND created_at >= :start AND created_at < :end THEN amount ELSE 0 END) as today_deposit,
                SUM(CASE WHEN type = 'withdraw' AND status = 'completed' AND created_at >= :start AND created_at < :end THEN amount ELSE 0 END) as today_withdraw,
                COUNT(CASE WHEN status IN ('pending', 'processing') THEN 1 END) as pending_tx,
                SUM(CASE WHEN type IN ('task_reward', 'commission') AND status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as earnings_30d,
                SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits_irt,
                SUM(CASE WHEN type = 'withdraw' AND status = 'completed' THEN amount ELSE 0 END) as total_withdraws_irt
            FROM transactions 
            WHERE user_id = :uid",
            ['uid' => $userId, 'start' => $todayStart, 'end' => $todayEnd]
        );

        // 2. Wallet Info
        $walletInfo = $this->db->fetch("
            SELECT balance_irt, balance_usdt, locked_irt 
            FROM wallets 
            WHERE user_id = :uid LIMIT 1",
            ['uid' => $userId]
        );
        if (!$walletInfo) {
            $walletInfo = (object)[
                'balance_irt' => 0.0,
                'balance_usdt' => 0.0,
                'locked_irt' => 0.0
            ];
        }

        // 3. Social Task Executions Statistics
        $taskSummary = $this->db->fetch("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN reward_amount ELSE 0 END), 0) as earned
            FROM social_task_executions
            WHERE executor_id = :uid",
            ['uid' => $userId]
        );

        // 4. Referral System Info
        $referralSummary = $this->db->fetch("
            SELECT 
                COUNT(*) as referred_count,
                COALESCE(SUM(commission_amount), 0) as total_earned_irt
            FROM referral_commissions
            WHERE referrer_id = :uid AND status = 'paid'",
            ['uid' => $userId]
        );

        // 5. Campaigns (Active Ads) Info
        $campaignsCount = (int)$this->db->fetchColumn("
            SELECT COUNT(*) 
            FROM ads 
            WHERE user_id = :uid",
            ['uid' => $userId]
        );

        $recentCampaigns = $this->db->fetchAll("
            SELECT id, title, platform, task_type, remaining_count, status, created_at
            FROM ads
            WHERE user_id = :uid
            ORDER BY id DESC LIMIT 5",
            ['uid' => $userId]
        );

        // 6. User Level Slug
        $userLevel = $this->db->fetch("
            SELECT level_slug, level_expires_at, level_type 
            FROM users 
            WHERE id = :uid LIMIT 1",
            ['uid' => $userId]
        );

        // 7. Recent Transactions List
        $lastTransactions = $this->db->fetchAll("
            SELECT id, type, currency, amount, status, created_at
            FROM transactions
            WHERE user_id = :uid
            ORDER BY id DESC LIMIT 10",
            ['uid' => $userId]
        );

        $stats = [
            'today_deposit'      => (float)($txSummary->today_deposit ?? 0),
            'today_withdraw'     => (float)($txSummary->today_withdraw ?? 0),
            'pending_tx'         => (int)($txSummary->pending_tx ?? 0),
            'earnings_30d'       => (float)($txSummary->earnings_30d ?? 0),
            'last_transactions'  => $lastTransactions,

            'wallet' => [
                'balance_irt' => (float)$walletInfo->balance_irt,
                'balance_usdt' => (float)$walletInfo->balance_usdt,
                'locked_irt' => (float)$walletInfo->locked_irt,
            ],
            'tasks' => [
                'completed' => (int)($taskSummary->completed ?? 0),
                'pending' => (int)($taskSummary->pending ?? 0),
                'rejected' => (int)($taskSummary->rejected ?? 0),
                'total' => (int)($taskSummary->total ?? 0),
                'earned' => (float)($taskSummary->earned ?? 0),
            ],
            'transactions' => [
                'total_deposits_irt' => (float)($txSummary->total_deposits_irt ?? 0),
                'total_withdraws_irt' => (float)($txSummary->total_withdraws_irt ?? 0),
                'pending_count' => (int)($txSummary->pending_tx ?? 0),
                'recent' => $lastTransactions,
            ],
            'campaigns' => [
                'total' => $campaignsCount,
                'recent' => $recentCampaigns,
            ],
            'level' => [
                'name' => strtoupper($userLevel->level_slug ?? 'silver'),
                'slug' => strtolower($userLevel->level_slug ?? 'silver'),
                'progress' => 0,
                'is_max' => ($userLevel->level_slug ?? 'silver') === 'diamond',
                'current' => $userLevel->level_slug ?? 'silver',
                'next' => null,
                'details' => [],
            ],
            'referral' => [
                'referred_count' => (int)($referralSummary->referred_count ?? 0),
                'total_earned_irt' => (float)($referralSummary->total_earned_irt ?? 0),
                'pending_irt' => 0.0,
                'paid_count' => (int)($referralSummary->referred_count ?? 0),
            ],
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
