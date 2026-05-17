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

    public function checkDailyLimit(int $userId, int $limit): bool
    {
        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        // Pre-insert an empty limit record if it does not exist to allow lock acquisition
        $insertSql = "INSERT IGNORE INTO " . static::$table . " 
            (user_id, limit_date, withdrawal_count, last_withdrawal_at, created_at, updated_at)
            VALUES (?, ?, 0, ?, ?, ?)";
        
        $stmt = $this->db->prepare($insertSql);
        $stmt->execute([$userId, $today, $now, $now, $now]);

        // Pessimistically lock the limit row for the user today
        $lockSql = "SELECT withdrawal_count FROM " . static::$table . " 
            WHERE user_id = ? AND limit_date = ? FOR UPDATE";
        
        $stmt = $this->db->prepare($lockSql);
        $stmt->execute([$userId, $today]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$row) {
            return true;
        }
        return ((int)$row->withdrawal_count) < $limit;
    }

    /**
     * افزایش شمارنده برداشت روزانه به صورت کاملاً اتمیک و با گارانتی سقف برداشت
     */
    public function incrementDailyCount(int $userId, int $limit): bool
    {
        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        // Pre-insert an empty limit record if it does not exist to allow lock acquisition
        $insertSql = "INSERT IGNORE INTO " . static::$table . " 
            (user_id, limit_date, withdrawal_count, last_withdrawal_at, created_at, updated_at)
            VALUES (?, ?, 0, ?, ?, ?)";
        
        $stmt = $this->db->prepare($insertSql);
        $stmt->execute([$userId, $today, $now, $now, $now]);

        $sql = "UPDATE " . static::$table . " 
                SET withdrawal_count = withdrawal_count + 1,
                    last_withdrawal_at = ?,
                    updated_at = ?
                WHERE user_id = ? AND limit_date = ? AND withdrawal_count < ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$now, $now, $userId, $today, $limit]);
        return $stmt->rowCount() > 0;
    }
}