<?php

declare(strict_types=1);

namespace App\Services\User;

use Core\Database;

class UserDashboardService extends \App\Services\BaseService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getStats(int $userId): array
    {
        $today = \date('Y-m-d');

        $todayDeposit = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions 
             WHERE user_id = :uid AND type = 'deposit' AND status = 'completed' AND DATE(created_at) = :d",
            ['uid' => $userId, 'd' => $today]
        );

        $todayWithdraw = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions 
             WHERE user_id = :uid AND type = 'withdraw' AND status = 'completed' AND DATE(created_at) = :d",
            ['uid' => $userId, 'd' => $today]
        );

        $pendingTx = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions 
             WHERE user_id = :uid AND status IN ('pending','processing')",
            ['uid' => $userId]
        );

        $totalEarningsMonth = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
             WHERE user_id = :uid AND type IN ('task_reward','commission') AND status = 'completed'
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

        return [
            'today_deposit'      => $todayDeposit,
            'today_withdraw'     => $todayWithdraw,
            'pending_tx'         => $pendingTx,
            'earnings_30d'       => $totalEarningsMonth,
            'last_transactions'  => $lastTransactions,
        ];
    }

    public function getRecentTaskExecutions(int $userId, int $limit = 5, int $offset = 0): array
    {
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        return $this->db->fetchAll(
            "SELECT ste.* FROM social_task_executions ste
             WHERE ste.executor_id = :uid
             ORDER BY ste.created_at DESC
             LIMIT :limit OFFSET :offset",
            [
                'uid' => $userId,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    public function getOpenTicketCount(int $userId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE user_id = :uid AND status IN ('open','pending')",
            ['uid' => $userId]
        );
    }
}
