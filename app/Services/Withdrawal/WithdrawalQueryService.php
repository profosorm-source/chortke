<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use Core\Database;
use App\Models\Withdrawal;
use App\Contracts\LoggerInterface;

class WithdrawalQueryService
{

    private Withdrawal $model;

    private \Core\Database $db;
    public function __construct(
        \Core\Database $db,
        Withdrawal $model
    )
    {        $this->db = $db;

        
        $this->model = $model;
    }

    /**
     * بررسی وجود درخواست برداشت معلق
     */
    public function hasPendingWithdrawal(int $userId, bool $forUpdate = false): bool
    {
        return $this->model->hasPendingWithdrawal($userId, $forUpdate);
    }

    /**
     * دریافت لیست درخواست‌های برداشت کاربر
     */
    public function getUserWithdrawals(int $userId): array
    {
        return $this->model->getUserWithdrawals($userId);
    }

    /**
     * دریافت اطلاعات سقف‌های مالی برداشت کاربر
     */
    public function getLimitsForUser(int $userId, string $currency): array
    {
        $currency = strtoupper($currency);
        $dailyLimit = $currency === 'USDT' ? '5000.00000000' : '50000000.0000';
        
        $sql = "SELECT SUM(amount) as used_today FROM withdrawals 
                WHERE user_id = ? AND currency = ? 
                  AND status IN ('pending', 'processing', 'completed')
                  AND created_at >= DATE(NOW())";
                  
        $row = $this->db->selectOne($sql, [$userId, strtolower($currency)]);
        $usedToday = (string)($row->used_today ?? '0');
        
        $remaining = \Core\ValueObjects\Money::fromString((string)($dailyLimit))->subtract(\Core\ValueObjects\Money::fromString((string)($usedToday)))->getAmount();
        if (\Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($remaining)))) {
            $remaining = '0';
        }

        return [
            'daily_limit'     => $dailyLimit,
            'used_today'      => $usedToday,
            'remaining_limit' => $remaining,
        ];
    }

    public function findById(int $withdrawalId): ?object
    {
        return $this->model->find($withdrawalId);
    }

    public function getSummaryStats(): array
    {
        return $this->model->getSummaryStats();
    }
}