<?php

namespace App\Models;

use Core\Model;

class CustomTaskTransaction extends Model
{
    protected string $table = 'custom_task_transactions';

    public function findByIdempotencyKey(string $key): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE idempotency_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public function create(array $data): object
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table}
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
        $stmt2 = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ? LIMIT 1");
        $stmt2->execute([$id]);
        return $stmt2->fetch(\PDO::FETCH_OBJ);
    }

    public function sumByTaskAndType(int $taskId, string $type): float
    {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->table} WHERE task_id = ? AND type = ?");
        $stmt->execute([$taskId, $type]);
        return (float)$stmt->fetchColumn();
    }
}