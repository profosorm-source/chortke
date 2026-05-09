<?php

namespace App\Models;

use Core\Model;

class CryptoDepositIntent extends Model
{
    protected static string $table = 'crypto_deposit_intents';

    public function validateIntentData(string $network, float $expectedAmount, string $address): void
    {
        $allowedNetworks = ['trc20', 'erc20', 'bep20', 'trg20', 'btc', 'ltc'];
        if (!\in_array(\strtolower($network), $allowedNetworks, true)) {
            throw new \InvalidArgumentException("Unsupported crypto network: " . $network);
        }

        if ($expectedAmount <= 0.0) {
            throw new \InvalidArgumentException("Expected amount must be positive.");
        }

        if (\strlen($address) < 26 || \strlen($address) > 100 || !\preg_match('/^[a-zA-Z0-9]+$/', $address)) {
            throw new \InvalidArgumentException("Invalid crypto wallet address format.");
        }
    }

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
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE id = ? FOR UPDATE");
            $stmt->execute([$intentId]);
            $intent = $stmt->fetch(\PDO::FETCH_OBJ);

            if ($intent && $intent->status === 'open' && \strtotime($intent->expires_at) < \time()) {
                $stmt = $this->db->prepare("UPDATE " . static::$table . " SET status = 'expired', updated_at = NOW() WHERE id = ?");
                $stmt->execute([$intentId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
        }
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
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $intent = $stmt->fetch(\PDO::FETCH_OBJ);

            if ($intent && $intent->status === 'open') {
                $stmt = $this->db->prepare("UPDATE " . static::$table . " SET status = 'claimed', claimed_at = NOW(), updated_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
                $this->db->commit();
                return true;
            }

            $this->db->rollBack();
            return false;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
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