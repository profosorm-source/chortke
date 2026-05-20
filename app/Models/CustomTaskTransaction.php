<?php

namespace App\Models;

use Core\Model;

class CustomTaskTransaction extends Model
{
    protected static string $table = 'custom_task_transactions';

    public function findByIdempotencyKey(string $key): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE idempotency_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public function create(array $data): object
    {
        // Validation
        $allowedTypes = ['budget_allocation', 'reward_payout', 'refund', 'penalty'];
        if (!isset($data['type']) || !in_array($data['type'], $allowedTypes, true)) {
            throw new \InvalidArgumentException('Invalid transaction type');
        }
        
        if (!isset($data['amount']) || !is_numeric($data['amount']) || (float)$data['amount'] < 0) {
            throw new \InvalidArgumentException('Invalid amount');
        }
        
        if (empty($data['idempotency_key'])) {
            throw new \InvalidArgumentException('Idempotency key is required');
        }
        
        // Check duplicate
        $existing = $this->findByIdempotencyKey($data['idempotency_key']);
        if ($existing) {
            return $existing;
        }

        $stmt = $this->db->prepare("
            INSERT INTO " . static::$table . "
            (task_id, submission_id, actor_id, type, amount, currency, idempotency_key, meta_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $data['task_id'] ?? null,
            $data['submission_id'] ?? null,
            $data['actor_id'] ?? null,
            $data['type'],
            $data['amount'],
            $data['currency'] ?? 'IRT',
            $data['idempotency_key'],
            json_encode($data['meta'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int)$this->db->lastInsertId();
        $stmt2 = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE id = ? LIMIT 1");
        $stmt2->execute([$id]);
        return $stmt2->fetch(\PDO::FETCH_OBJ);
    }

    public function sumByTaskAndType(int $taskId, string $type): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM " . static::$table . " WHERE task_id = ? AND type = ?");
        $stmt->execute([$taskId, $type]);
        return (float)$stmt->fetchColumn();
    }
}