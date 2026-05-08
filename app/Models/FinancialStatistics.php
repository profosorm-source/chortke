<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * FinancialStatistics Model — Statistics Data Access Layer
 * 
 * مسئولیت: آمارگیری از تراکنش‌ها، سودها، کیف‌پول‌ها
 * استفاده می‌شود در: KpiService, AnalyticsService
 */
class FinancialStatistics extends Model
{
    /**
     * کل درآمد (روز معین)
     */
    public function getTotalRevenue(?string $dateFrom = null, ?string $dateTo = null): float
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT SUM(amount) as total FROM transactions WHERE type = 'deposit' {$whereClause}"
        );
        $stmt->execute($params);

        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * کل برداشت‌ها (روز معین)
     */
    public function getTotalWithdrawals(?string $dateFrom = null, ?string $dateTo = null): float
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT SUM(amount) as total FROM transactions WHERE type = 'withdrawal' {$whereClause}"
        );
        $stmt->execute($params);

        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * آمارهای تراکنش
     */
    public function getTransactionStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN type = 'deposit' THEN 1 ELSE 0 END) as deposits,
                SUM(CASE WHEN type = 'withdrawal' THEN 1 ELSE 0 END) as withdrawals,
                AVG(amount) as avg_amount,
                MAX(amount) as max_amount
             FROM transactions {$whereClause}"
        );
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'total_count' => (int) ($result['total'] ?? 0),
            'deposits' => (int) ($result['deposits'] ?? 0),
            'withdrawals' => (int) ($result['withdrawals'] ?? 0),
            'avg_amount' => (float) ($result['avg_amount'] ?? 0),
            'max_amount' => (float) ($result['max_amount'] ?? 0),
        ];
    }

    /**
     * کل موجودی کیف‌پول‌ها
     */
    public function getTotalWalletBalance(): float
    {
        $stmt = $this->db->prepare(
            "SELECT SUM(balance) as total FROM wallets WHERE is_active = 1"
        );
        $stmt->execute();

        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * میانگین موجودی کیف‌پول
     */
    public function getAverageWalletBalance(): float
    {
        $stmt = $this->db->prepare(
            "SELECT AVG(balance) as avg FROM wallets WHERE is_active = 1"
        );
        $stmt->execute();

        return (float) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * درآمد بر حسب نوع تراکنش (روزانه)
     */
    public function getDailyRevenue(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                DATE(created_at) as date,
                SUM(amount) as total,
                COUNT(*) as count
             FROM transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC"
        );
        $stmt->execute([$days]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * کاربران بر حسب سطح موجودی
     */
    public function getUsersByBalanceLevel(): array
    {
        $stmt = $this->db->prepare(
            "SELECT 
                CASE 
                    WHEN balance = 0 THEN 'zero'
                    WHEN balance < 1000 THEN 'low'
                    WHEN balance < 10000 THEN 'medium'
                    WHEN balance < 100000 THEN 'high'
                    ELSE 'very_high'
                END as level,
                COUNT(*) as count
             FROM wallets
             WHERE is_active = 1
             GROUP BY level
             ORDER BY level"
        );
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * آمارهای سود
     */
    public function getProfitStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $where = [];
        $params = [];

        if ($dateFrom !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(
            "SELECT 
                COUNT(*) as total,
                SUM(profit) as total_profit,
                AVG(profit) as avg_profit,
                MAX(profit) as max_profit,
                MIN(profit) as min_profit
             FROM investment_profit {$whereClause}"
        );
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'total_count' => (int) ($result['total'] ?? 0),
            'total_profit' => (float) ($result['total_profit'] ?? 0),
            'avg_profit' => (float) ($result['avg_profit'] ?? 0),
            'max_profit' => (float) ($result['max_profit'] ?? 0),
            'min_profit' => (float) ($result['min_profit'] ?? 0),
        ];
    }

    /**
     * کاربران با بالاترین موجودی
     */
    public function getTopWallets(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id, u.full_name, u.email, w.balance
             FROM wallets w
             JOIN users u ON w.user_id = u.id
             WHERE w.is_active = 1
             ORDER BY w.balance DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * خلاصه آمارهای مالی
     */
    public function getFinancialSummary(): array
    {
        return [
            'total_revenue' => $this->getTotalRevenue(),
            'total_withdrawals' => $this->getTotalWithdrawals(),
            'total_wallet_balance' => $this->getTotalWalletBalance(),
            'avg_wallet_balance' => $this->getAverageWalletBalance(),
            'transaction_stats' => $this->getTransactionStats(),
            'profit_stats' => $this->getProfitStats(),
            'balance_levels' => $this->getUsersByBalanceLevel(),
            'top_wallets' => $this->getTopWallets(5),
        ];
    }
}
