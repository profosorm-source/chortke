<?php

namespace App\Models;

use Core\Model;

class ScheduledPayment extends Model
{
    protected static string $table = 'scheduled_payments';

    public function createSchedule(array $data): ?object
    {
        $data['user_id'] = (int)($data['user_id'] ?? 0);
        $data['amount'] = (float)($data['amount'] ?? 0);
        $data['currency'] = strtolower($data['currency'] ?? 'irt');
        $data['frequency'] = $data['frequency'] ?? 'one_time';
        $data['next_run_at'] = $data['next_run_at'] ?? date('Y-m-d H:i:s');
        $data['status'] = $data['status'] ?? 'active';
        $data['description'] = $data['description'] ?? null;
        $data['metadata'] = isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : ($data['metadata'] ?? null);
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $id = parent::create($data);
        return is_int($id) ? $this->find($id) : null;
    }

    public function getDuePayments(int $limit = 50): array
    {
        $limit = max(1, (int)$limit);
        $sql = "SELECT * FROM " . static::$table . " WHERE status = 'active' AND next_run_at <= NOW() ORDER BY next_run_at ASC LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function updateNextRun(int $id, string $nextRunAt, string $status = 'active'): bool
    {
        return $this->update($id, [
            'next_run_at' => $nextRunAt,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->update($id, ['status' => $status]);
    }
}
