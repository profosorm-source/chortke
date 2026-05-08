<?php

namespace App\Models;

use Core\Model;

class PaymentLog extends Model
{
    protected static string $table = 'payment_logs';

    public function findByAuthority(string $authority): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM " . static::$table . " WHERE authority = ? LIMIT 1",
            [$authority]
        ) ?: null;
    }

    public function getUserPayments(int $userId, int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM " . static::$table . "
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    public function getSuccessfulPayments(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM " . static::$table . "
             WHERE user_id = ? AND status IN ('verified','completed')
             ORDER BY created_at DESC",
            [$userId]
        );
    }

    public function sumPayments(int $userId, string $status = 'completed'): float
    {
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM " . static::$table . "
             WHERE user_id = ? AND status = ?",
            [$userId, $status]
        );
    }
}
