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
     * M25: Use column whitelist to prevent unexpected fields from being inserted
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        // M25: Whitelist of allowed columns for this table
        $allowedColumns = [
            'user_id', 'submission_id', 'period', 'status',
            'gross_amount', 'platform_fee', 'net_user_amount',
            'metadata', 'is_deleted', 'created_at', 'updated_at'
        ];

        // Only keep allowed columns
        $sanitizedData = [];
        foreach ($allowedColumns as $col) {
            if (\array_key_exists($col, $data)) {
                $sanitizedData[$col] = $data[$col];
            }
        }

        $sanitizedData['created_at'] = $sanitizedData['created_at'] ?? $now;
        $sanitizedData['updated_at'] = $sanitizedData['updated_at'] ?? $now;
        $sanitizedData['is_deleted'] = $sanitizedData['is_deleted'] ?? 0;

        $columns = \array_keys($sanitizedData);
        $values  = \array_values($sanitizedData);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        // L-07: Use static::$table instead of hard-coded 'content_revenues'
        $sql = "INSERT INTO `" . static::$table . "` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

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

        $stmt = $this->db->prepare(
            "SELECT cr.*, cs.title as video_title, cs.platform
             FROM content_revenues cr
             JOIN content_submissions cs ON cr.submission_id = cs.id
             WHERE cr.user_id = :user_id AND cr.is_deleted = 0
             ORDER BY cr.period DESC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
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
            $sql .= " AND cr.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['user_id'])) {
            $sql .= " AND cr.user_id = :user_id";
            $params['user_id'] = (int)$filters['user_id'];
        }
        if (!empty($filters['period'])) {
            $sql .= " AND cr.period = :period";
            $params['period'] = $filters['period'];
        }

        $sql .= " ORDER BY cr.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
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

        // Whitelist allowed fields
        $allowedFields = [
            'status', 'total_revenue', 'site_share_amount',
            'net_user_amount', 'tax_amount', 'admin_notes',
            'reviewed_by', 'reviewed_at', 'paid_at', 'is_deleted'
        ];

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            if (\in_array($k, $allowedFields, true)) {
                $fields[] = "`{$k}` = ?";
                $values[] = $v;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "`updated_at` = NOW()";
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