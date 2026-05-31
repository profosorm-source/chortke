<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

/**
 * TransactionQuery (CQRS - Read Model)
 * 
 * بر اساس الگوی CQRS، تمام عملیات‌های سنگین خواندن (Read/Query) از جمله گزارش‌گیری‌ها 
 * و ساخت داشبوردها به این کلاس منتقل شده‌اند.
 * این کار به ما اجازه می‌دهد در آینده این کلاس را به یک دیتابیس Replica متصل کنیم، 
 * تا هنگام گزارش‌گیری سنگین، هسته‌ی اصلی (Master DB) قفل نشود.
 */
class TransactionQuery
{
    private Database $db;
    private string $table = 'transactions';

    public function __construct(Database $db)
    {
        // در مقیاس‌های بزرگتر می‌توانیم اینجا یک کلاینت DB جداگانه (Read Replica) به جای Master تزریق کنیم
        $this->db = $db;
    }

    public function getRecentByUserId(int $userId, int $limit = 100): array
    {
        $stmt = $this->db->query(
            "SELECT id, type, amount, status, created_at FROM {$this->table} WHERE user_id = ? ORDER BY created_at DESC LIMIT ?",
            [$userId, $limit]
        );
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function findByTransactionId(string $transactionId): ?object
    {
        $sql = "SELECT * FROM {$this->table} WHERE transaction_id = :transaction_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['transaction_id' => $transactionId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    public function getUserStats(int $userId): object
    {
        $sql = "
            SELECT
                currency,
                SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
                SUM(CASE WHEN type = 'withdraw' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawals,
                COUNT(CASE WHEN type = 'deposit' THEN 1 END) as deposit_count,
                COUNT(CASE WHEN type = 'withdraw' THEN 1 END) as withdrawal_count
            FROM {$this->table}
            WHERE user_id = :user_id
            GROUP BY currency
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $stats = (object)[
            'irt'  => (object)['total_deposits' => 0, 'total_withdrawals' => 0, 'deposit_count' => 0, 'withdrawal_count' => 0],
            'usdt' => (object)['total_deposits' => 0, 'total_withdrawals' => 0, 'deposit_count' => 0, 'withdrawal_count' => 0],
        ];

        foreach ($results as $result) {
            $cur = (string)($result->currency ?? '');
            if ($cur !== '') {
                $stats->{$cur} = $result;
            }
        }
        return $stats;
    }

    public function getFinancialStats(string $currency = 'irt'): array
    {
        // Materialized View: خواندن نتیجه از جدول از پیش محاسبه‌شده
        $row = $this->db->fetch("SELECT * FROM mv_dashboard_stats WHERE currency = ?", [$currency]);
        
        if (!$row) {
            // اگر هنوز محاسبه نشده، مقدار پیش‌فرض برگردانده شود (یا در لحظه یکبار محاسبه شود)
            $this->refreshMaterializedView();
            $row = $this->db->fetch("SELECT * FROM mv_dashboard_stats WHERE currency = ?", [$currency]);
            if (!$row) return []; // Fallback
        }

        return [
            'currency' => $currency,
            'total_deposits' => (float)$row->total_deposits,
            'total_withdrawals' => (float)$row->total_withdrawals,
            'today_deposits' => (float)$row->today_deposits,
            'today_withdrawals' => (float)$row->today_withdrawals,
            'pending_transactions' => (int)$row->pending_transactions,
            'site_revenue' => (float)$row->site_revenue,
            'today_revenue' => (float)$row->today_revenue,
            'weekly_revenue' => (float)$row->weekly_revenue,
            'monthly_revenue' => (float)$row->monthly_revenue,
            'total_transactions' => (int)$row->total_transactions,
            'active_users' => (int)$row->active_users,
            'arpu' => $row->active_users > 0 ? (float)bcdiv((string)$row->monthly_revenue, (string)$row->active_users, strtolower($currency) === 'usdt' ? 8 : 4) : 0.0,
            'net_flow' => (float)\Core\ValueObjects\Money::fromString((string)((string)$row->total_deposits))->subtract(\Core\ValueObjects\Money::fromString((string)((string)$row->total_withdrawals)))->getAmount() === 'usdt' ? 8 : 4),
            'last_updated' => $row->updated_at
        ];
    }

    /**
     * تازه‌سازی داده‌های Materialized View
     * این متد باید توسط Cron Job در پس‌زمینه (مثلاً هر ۵ دقیقه) صدا زده شود.
     */
    public function refreshMaterializedView(): void
    {
        $currencies = ['irt', 'usdt'];
        foreach ($currencies as $currency) {
            $row = $this->db->fetch("
                SELECT
                    SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
                    SUM(CASE WHEN type = 'withdraw' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawals,
                    SUM(CASE WHEN type = 'deposit' AND status = 'completed' AND DATE(created_at) = CURDATE() THEN amount ELSE 0 END) as today_deposits,
                    SUM(CASE WHEN type = 'withdraw' AND status = 'completed' AND DATE(created_at) = CURDATE() THEN amount ELSE 0 END) as today_withdrawals,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
                    SUM(CASE WHEN type IN ('commission_site','tax','fee') AND status = 'completed' THEN amount ELSE 0 END) as site_revenue,
                    SUM(CASE WHEN type IN ('commission_site','tax','fee') AND status = 'completed' AND DATE(created_at) = CURDATE() THEN amount ELSE 0 END) as today_revenue,
                    SUM(CASE WHEN type IN ('commission_site','tax','fee') AND status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN amount ELSE 0 END) as weekly_revenue,
                    SUM(CASE WHEN type IN ('commission_site','tax','fee') AND status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END) as monthly_revenue,
                    COUNT(*) as total_transactions
                FROM {$this->table}
                WHERE currency = ?
            ", [$currency]);

            $activeUsers = (int)$this->db->fetchColumn("
                SELECT COUNT(DISTINCT user_id) FROM {$this->table}
                WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");

            $this->db->query("
                INSERT INTO mv_dashboard_stats (
                    currency, total_deposits, total_withdrawals, today_deposits, today_withdrawals,
                    pending_transactions, site_revenue, today_revenue, weekly_revenue, monthly_revenue,
                    total_transactions, active_users, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    total_deposits = VALUES(total_deposits),
                    total_withdrawals = VALUES(total_withdrawals),
                    today_deposits = VALUES(today_deposits),
                    today_withdrawals = VALUES(today_withdrawals),
                    pending_transactions = VALUES(pending_transactions),
                    site_revenue = VALUES(site_revenue),
                    today_revenue = VALUES(today_revenue),
                    weekly_revenue = VALUES(weekly_revenue),
                    monthly_revenue = VALUES(monthly_revenue),
                    total_transactions = VALUES(total_transactions),
                    active_users = VALUES(active_users),
                    updated_at = VALUES(updated_at)
            ", [
                $currency,
                $row->total_deposits ?? 0,
                $row->total_withdrawals ?? 0,
                $row->today_deposits ?? 0,
                $row->today_withdrawals ?? 0,
                $row->pending_transactions ?? 0,
                $row->site_revenue ?? 0,
                $row->today_revenue ?? 0,
                $row->weekly_revenue ?? 0,
                $row->monthly_revenue ?? 0,
                $row->total_transactions ?? 0,
                $activeUsers
            ]);
        }
    }
}
