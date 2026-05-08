<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class LotteryRound extends Model {
    public const STATUS_ACTIVE = 'active';
    public const STATUS_VOTING = 'voting';
    public const STATUS_CALCULATING = 'calculating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_WEEKLY = 'weekly';
    public const TYPE_MONTHLY = 'monthly';
/**
     * ایجاد دوره قرعه‌کشی
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $data['is_deleted'] = $data['is_deleted'] ?? 0;

        $columns = \array_keys($data);
        $values  = \array_values($data);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `lottery_rounds` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) return null;

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM lottery_rounds WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function findWithWinner(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT lr.*, u.full_name as winner_name
             FROM lottery_rounds lr
             LEFT JOIN users u ON lr.winner_user_id = u.id
             WHERE lr.id = ? AND lr.is_deleted = 0
             LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function getActiveRound(): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM lottery_rounds
             WHERE status IN (?, ?) AND is_deleted = 0
             ORDER BY start_date DESC
             LIMIT 1",
            [self::STATUS_ACTIVE, self::STATUS_VOTING]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT lr.*, u.full_name as winner_name
                FROM lottery_rounds lr
                LEFT JOIN users u ON lr.winner_user_id = u.id
                WHERE lr.is_deleted = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND lr.status = ?";
            $params[] = $filters['status'];
        }

        $sql .= " ORDER BY lr.start_date DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM lottery_rounds WHERE is_deleted = 0";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    public function update(int $id, array $data): bool
    {
        if (empty($data)) return false;

        $data['updated_at'] = \date('Y-m-d H:i:s');

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            $fields[] = "`{$k}` = ?";
            $values[] = $v;
        }

        $values[] = $id;

        $sql = "UPDATE lottery_rounds
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    public function getCompletedRounds(int $limit = 10): array
    {
        $limit = \max(1, (int)$limit);

        $stmt = $this->db->query(
            "SELECT lr.*, u.full_name as winner_name
             FROM lottery_rounds lr
             LEFT JOIN users u ON lr.winner_user_id = u.id
             WHERE lr.status = ? AND lr.is_deleted = 0
             ORDER BY lr.end_date DESC
             LIMIT {$limit}",
            [self::STATUS_COMPLETED]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function getStats(): object
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN prize_amount ELSE 0 END), 0) as total_prizes
             FROM lottery_rounds
             WHERE is_deleted = 0"
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row ?: (object)[];
    }
}