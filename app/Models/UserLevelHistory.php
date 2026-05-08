<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class UserLevelHistory extends Model {
/**
     * ثبت تغییر سطح
     */
    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_level_history 
            (user_id, from_level, to_level, change_type, reason, metadata, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        $result = $stmt->execute([
            $data['user_id'],
            $data['from_level'] ?? null,
            $data['to_level'],
            $data['change_type'],
            $data['reason'] ?? null,
            isset($data['metadata']) ? \json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
            $data['ip_address'] ?? get_client_ip(),
        ]);

        if (!$result) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    /**
     * یافتن
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM user_level_history WHERE id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * تاریخچه کاربر
     */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT h.*,
                   fl.name AS from_level_name,
                   tl.name AS to_level_name
            FROM user_level_history h
            LEFT JOIN user_levels fl ON fl.slug = h.from_level
            LEFT JOIN user_levels tl ON tl.slug = h.to_level
            WHERE h.user_id = ?
            ORDER BY h.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$userId, $limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * لیست ادمین
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "h.user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['change_type'])) {
            $where[] = "h.change_type = ?";
            $params[] = $filters['change_type'];
        }
        if (!empty($filters['to_level'])) {
            $where[] = "h.to_level = ?";
            $params[] = $filters['to_level'];
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT h.*, 
                   u.full_name AS user_name,
                   u.email AS user_email,
                   fl.name AS from_level_name,
                   tl.name AS to_level_name
            FROM user_level_history h
            LEFT JOIN users u ON u.id = h.user_id
            LEFT JOIN user_levels fl ON fl.slug = h.from_level
            LEFT JOIN user_levels tl ON tl.slug = h.to_level
            WHERE {$whereStr}
            ORDER BY h.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد
     */
    public function adminCount(array $filters = []): int
    {
        $where = ["1=1"];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = "h.user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['change_type'])) {
            $where[] = "h.change_type = ?";
            $params[] = $filters['change_type'];
        }
        if (!empty($filters['to_level'])) {
            $where[] = "h.to_level = ?";
            $params[] = $filters['to_level'];
        }

        $whereStr = \implode(' AND ', $where);

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_level_history h WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}