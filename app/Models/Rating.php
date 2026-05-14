<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * Rating Model - مدل اشتراکی نظرات و امتیازات عددی
 */
class Rating extends Model
{
    protected static string $table = 'ratings';

    public function getAverage(int $ratedId, string $ratedType): float
    {
        return (float)$this->db->table(static::$table)
            ->where('rated_id', '=', $ratedId)
            ->where('rated_type', '=', $ratedType)
            ->avg('rating');
    }

    /**
     * بررسی اینکه آیا کاربر قبلا برای این مرجع امتیاز ثبت کرده است یا خیر
     */
    public function hasRated(int $raterId, string $refType, int $refId): bool
    {
        $row = $this->db->table(static::$table)
            ->where('rater_id', '=', $raterId)
            ->where('ref_type', '=', $refType)
            ->where('ref_id', '=', $refId)
            ->selectRaw('1')
            ->first();
            
        return (bool)$row;
    }
}
