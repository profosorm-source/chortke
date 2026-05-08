<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class ContentRevenue extends Model {
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
/**
     * ایجاد رکورد درآمد
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

        $sql = "INSERT INTO `content_revenues` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) return null;

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM content_revenues WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function findWithDetails(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT cr.*, cs.title as video_title, cs.video_url, cs.platform,
                    u.full_name as user_name, u.email as user_email
             FROM content_revenues cr
             JOIN content_submissions cs ON cr.submission_id = cs.id
             JOIN users u ON cr.user_id = u.id
             WHERE cr.id = ? AND cr.is_deleted = 0
             LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function getBySubmission(int $submissionId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM content_revenues
             WHERE submission_id = ? AND is_deleted = 0
             ORDER BY period DESC",
            [$submissionId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $stmt = $this->db->query(
            "SELECT cr.*, cs.title as video_title, cs.platform
             FROM content_revenues cr
             JOIN content_submissions cs ON cr.submission_id = cs.id
             WHERE cr.user_id = ? AND cr.is_deleted = 0
             ORDER BY cr.period DESC
             LIMIT {$limit} OFFSET {$offset}",
            [$userId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total
             FROM content_revenues
             WHERE user_id = ? AND is_deleted = 0",
            [$userId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0);
    }

    public function getTotalUserRevenue(int $userId, ?string $status = null): float
    {
        $sql = "SELECT COALESCE(SUM(net_user_amount), 0) as total
                FROM content_revenues
                WHERE user_id = ? AND is_deleted = 0";
        $params = [$userId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (float)($row->total ?? 0);
    }

    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT cr.*, cs.title as video_title, cs.platform,
                       u.full_name as user_name
                FROM content_revenues cr
                JOIN content_submissions cs ON cr.submission_id = cs.id
                JOIN users u ON cr.user_id = u.id
                WHERE cr.is_deleted = 0";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND cr.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= " AND cr.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }
        if (!empty($filters['period'])) {
            $sql .= " AND cr.period = ?";
            $params[] = $filters['period'];
        }

        $sql .= " ORDER BY cr.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM content_revenues cr
                JOIN content_submissions cs ON cr.submission_id = cs.id
                WHERE cr.is_deleted = 0";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND cr.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= " AND cr.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    public function existsForPeriod(int $submissionId, string $period): bool
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total
             FROM content_revenues
             WHERE submission_id = ? AND period = ? AND is_deleted = 0",
            [$submissionId, $period]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0) > 0;
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

        $sql = "UPDATE content_revenues
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    public function getFinancialStats(): object
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total_records,
                COALESCE(SUM(total_revenue), 0) as total_revenue,
                COALESCE(SUM(site_share_amount), 0) as total_site_share,
                COALESCE(SUM(net_user_amount), 0) as total_user_paid,
                COALESCE(SUM(tax_amount), 0) as total_tax,
                SUM(CASE WHEN status = 'pending' THEN net_user_amount ELSE 0 END) as pending_amount,
                SUM(CASE WHEN status = 'paid' THEN net_user_amount ELSE 0 END) as paid_amount
             FROM content_revenues WHERE is_deleted = 0"
        );

        return $stmt ? ($stmt->fetch(\PDO::FETCH_OBJ) ?: (object)[]) : (object)[];
    }
}