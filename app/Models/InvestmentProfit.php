<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class InvestmentProfit extends Model {
/**
     * ایجاد رکورد سود/ضرر
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

        $sql = "INSERT INTO `investment_profits` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

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
            "SELECT * FROM investment_profits WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function getByInvestment(int $investmentId, int $limit = 50): array
    {
        $limit = \max(1, (int)$limit);

        $stmt = $this->db->query(
            "SELECT ip.*, t.pair, t.direction, t.open_price, t.close_price
             FROM investment_profits ip
             LEFT JOIN trading_records t ON ip.trading_record_id = t.id
             WHERE ip.investment_id = ? AND ip.is_deleted = 0
             ORDER BY ip.created_at DESC
             LIMIT {$limit}",
            [$investmentId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $stmt = $this->db->query(
            "SELECT ip.*, i.amount as investment_amount
             FROM investment_profits ip
             JOIN investments i ON ip.investment_id = i.id
             WHERE ip.user_id = ? AND ip.is_deleted = 0
             ORDER BY ip.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$userId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total FROM investment_profits WHERE user_id = ? AND is_deleted = 0",
            [$userId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0);
    }

    /**
     * مجموع سود/ضرر یک سرمایه‌گذاری
     */
    public function getTotalByInvestment(int $investmentId): object
    {
        $stmt = $this->db->query(
            "SELECT
                COALESCE(SUM(CASE WHEN net_amount > 0 THEN net_amount ELSE 0 END), 0) as total_profit,
                COALESCE(SUM(CASE WHEN net_amount < 0 THEN ABS(net_amount) ELSE 0 END), 0) as total_loss,
                COALESCE(SUM(net_amount), 0) as net_total
             FROM investment_profits
             WHERE investment_id = ? AND is_deleted = 0",
            [$investmentId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return $row ?: (object)[
            'total_profit' => 0,
            'total_loss' => 0,
            'net_total' => 0,
        ];
    }
}