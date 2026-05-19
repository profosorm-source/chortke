<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class UserLevelHistory extends Model {
    protected static string $table = 'user_level_history';
    /**
     * تولید امضای دیجیتال برای امنیت و عدم دستکاری تاریخچه تغییرات
     */
    public function generateSignature(int $userId, ?string $fromLevel, string $toLevel, string $changeType, ?string $reason, ?string $metadata, ?string $ipAddress): string
    {
        $payload = \implode('|', [
            $userId,
            $fromLevel ?? '',
            $toLevel,
            $changeType,
            $reason ?? '',
            $metadata ?? '',
            $ipAddress ?? '',
        ]);
        return \hash_hmac('sha256', $payload, \secure_key());
    }

    /**
     * اعتبارسنجی امضای دیجیتال تاریخچه تغییر سطح
     */
    public function verifySignature(object $row): bool
    {
        if (empty($row->signature)) {
            return false;
        }
        $expected = $this->generateSignature(
            (int)$row->user_id,
            $row->from_level,
            $row->to_level,
            $row->change_type,
            $row->reason,
            $row->metadata,
            $row->ip_address
        );
        return \hash_equals($expected, $row->signature);
    }

    /**
     * ثبت تغییر سطح
     */
    public function create(array $data): ?object
    {
        $userId = (int)$data['user_id'];
        $fromLevel = $data['from_level'] ?? null;
        $toLevel = $data['to_level'];
        $changeType = $data['change_type'];
        $reason = $data['reason'] ?? null;
        $metadata = isset($data['metadata']) ? \json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null;
        $ipAddress = $data['ip_address'] ?? get_client_ip();

        $signature = $this->generateSignature($userId, $fromLevel, $toLevel, $changeType, $reason, $metadata, $ipAddress);

        $stmt = $this->db->prepare("
            INSERT INTO user_level_history 
            (user_id, from_level, to_level, change_type, reason, metadata, ip_address, signature)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $result = $stmt->execute([
            $userId,
            $fromLevel,
            $toLevel,
            $changeType,
            $reason,
            $metadata,
            $ipAddress,
            $signature,
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
        if ($result) {
            $result->is_valid = $this->verifySignature($result);
        }
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
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
        foreach ($rows as $row) {
            $row->is_valid = $this->verifySignature($row);
        }
        return $rows;
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
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ);
        foreach ($rows as $row) {
            $row->is_valid = $this->verifySignature($row);
        }
        return $rows;
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