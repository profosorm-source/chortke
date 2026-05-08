<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class LotteryChanceLog extends Model {
/**
     * ثبت لاگ تغییر شانس
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        // اگر جدول این ستون‌ها را دارد
        $data['created_at'] = $data['created_at'] ?? $now;

        $columns = \array_keys($data);
        $values  = \array_values($data);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `lottery_chance_logs` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function getByParticipation(int $participationId, int $limit = 30): array
    {
        $limit = \max(1, (int)$limit);

        $stmt = $this->db->query(
            "SELECT * FROM lottery_chance_logs
             WHERE participation_id = ?
             ORDER BY date DESC
             LIMIT {$limit}",
            [$participationId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }
}