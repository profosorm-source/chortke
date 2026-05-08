<?php

namespace App\Models;

use Core\Model;

class CryptoDepositIntent extends Model
{
    protected static string $table = 'crypto_deposit_intents';

    public function getOpenIntentForUser(int $userId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . "
                WHERE user_id = :user_id AND status = 'open'
                ORDER BY id DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        return $row ?: null;
    }

    public function expireIfPassed(int $intentId): void
    {
        $sql = "UPDATE " . static::$table . "
                SET status='expired', updated_at=NOW()
                WHERE id=:id AND status='open' AND expires_at < NOW()";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $intentId]);
    }

    public function findByIdAndUser(int $id, int $userId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE id = :id AND user_id = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public function markAsClaimed(int $id): bool
    {
        $sql = "UPDATE " . static::$table . "
                SET status='claimed', claimed_at=NOW(), updated_at=NOW()
                WHERE id = :id AND status = 'open'";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function findOpenByNetworkAndAmount(string $network, float $expectedAmount): ?object
    {
        $sql = "SELECT id FROM " . static::$table . "
                WHERE network = :network AND status = 'open' AND expected_amount = :expected_amount
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['network' => $network, 'expected_amount' => $expectedAmount]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
    }
}