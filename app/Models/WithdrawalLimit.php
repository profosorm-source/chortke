<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * WithdrawalLimit Model
 */
class WithdrawalLimit extends Model
{
    protected static string $table = 'withdrawal_limits';

    protected array $fillable = [
        'user_id', 'limit_date', 'withdrawal_count', 'last_withdrawal_at'
    ];

    /**
     * بررسی محدودیت روزانه
     */
    public function checkDailyLimit(int $userId, int $limit): bool
    {
        $today = date('Y-m-d');
        $row = $this->db->table(static::$table)
            ->select('withdrawal_count')
            ->where('user_id', '=', $userId)
            ->where('limit_date', '=', $today)
            ->first();

        if (!$row) {
            return true;
        }
        return ((int)$row->withdrawal_count) < $limit;
    }

    /**
     * افزایش شمارنده برداشت روزانه (UPSERT اتمیک)
     */
    public function incrementDailyCount(int $userId): void
    {
        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        $sql = "INSERT INTO " . static::$table . " 
                    (user_id, limit_date, withdrawal_count, last_withdrawal_at, created_at, updated_at)
                VALUES (?, ?, 1, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    withdrawal_count = withdrawal_count + 1,
                    last_withdrawal_at = VALUES(last_withdrawal_at),
                    updated_at = VALUES(updated_at)";

        $this->db->query($sql, [$userId, $today, $now, $now, $now]);
    }
}