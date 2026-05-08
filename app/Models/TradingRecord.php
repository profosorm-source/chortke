<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class TradingRecord extends Model {
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_STOPPED = 'stopped';

    public const DIRECTION_BUY = 'buy';
    public const DIRECTION_SELL = 'sell';
/**
     * ایجاد ترید جدید
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

        $sql = "INSERT INTO `trading_records` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM trading_records WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function findWithAdmin(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT t.*, u.full_name as admin_name
             FROM trading_records t
             JOIN users u ON t.admin_id = u.id
             WHERE t.id = ? AND t.is_deleted = 0
             LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    /**
     * لیست تریدها
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT t.*, u.full_name as admin_name
                FROM trading_records t
                JOIN users u ON t.admin_id = u.id
                WHERE t.is_deleted = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['direction'])) {
            $sql .= " AND t.direction = ?";
            $params[] = $filters['direction'];
        }

        $sql .= " ORDER BY t.open_time DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total FROM trading_records WHERE is_deleted = 0";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['direction'])) {
            $sql .= " AND direction = ?";
            $params[] = $filters['direction'];
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    /**
     * تریدهای باز
     */
    public function getOpenTrades(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM trading_records
             WHERE status = ? AND is_deleted = 0
             ORDER BY open_time DESC",
            [self::STATUS_OPEN]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    /**
     * آخرین تریدهای بسته شده (برای نمایش به کاربر)
     */
    public function getRecentClosed(int $limit = 10): array
    {
        $limit = \max(1, (int)$limit);

        $stmt = $this->db->query(
            "SELECT * FROM trading_records
             WHERE status IN (?, ?) AND is_deleted = 0
             ORDER BY close_time DESC
             LIMIT {$limit}",
            [self::STATUS_CLOSED, self::STATUS_STOPPED]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    /**
     * بروزرسانی ترید
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = \date('Y-m-d H:i:s');

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            $fields[] = "`{$k}` = ?";
            $values[] = $v;
        }

        $values[] = $id;

        $sql = "UPDATE trading_records
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    /**
     * آمار تریدها
     */
    public function getStats(): object
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,
                SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed_count,
                SUM(CASE WHEN profit_loss_amount > 0 THEN 1 ELSE 0 END) as profit_count,
                SUM(CASE WHEN profit_loss_amount < 0 THEN 1 ELSE 0 END) as loss_count,
                COALESCE(SUM(CASE WHEN profit_loss_amount > 0 THEN profit_loss_amount ELSE 0 END), 0) as total_profit,
                COALESCE(SUM(CASE WHEN profit_loss_amount < 0 THEN ABS(profit_loss_amount) ELSE 0 END), 0) as total_loss
             FROM trading_records
             WHERE is_deleted = 0"
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row ?: (object)[];
    }
}