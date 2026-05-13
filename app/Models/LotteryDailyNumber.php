<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * LotteryDailyNumber Model
 */
class LotteryDailyNumber extends Model
{
    protected static string $table = 'lottery_daily_numbers';

    public function getByRoundAndDate(int $roundId, string $date): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('round_id', '=', $roundId)
            ->where('date', '=', $date)
            ->where('is_deleted', '=', 0)
            ->first();

        return $result ?: null;
    }

    public function getToday(int $roundId): ?object
    {
        return $this->getByRoundAndDate($roundId, date('Y-m-d'));
    }

    public function getByRound(int $roundId): array
    {
        return $this->db->table(static::$table)
            ->where('round_id', '=', $roundId)
            ->where('is_deleted', '=', 0)
            ->orderBy('date', 'ASC')
            ->get();
    }
}