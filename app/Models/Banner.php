<?php

namespace App\Models;

use Core\Database;
use Core\Model;

class Banner extends Model
{
    protected static string $table = 'banners';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function find(int $id): ?object
    {
        return $this->db->fetch(
            "SELECT b.*, u.full_name as user_name, c.full_name as creator_name
             FROM banners b
             LEFT JOIN users u ON b.user_id = u.id
             LEFT JOIN users c ON b.created_by = c.id
             WHERE b.id = ? AND b.deleted_at IS NULL",
            [$id]
        );
    }

    public function all($filters = [], $limit = 50, $offset = 0): array
    {
        $where = ["b.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['placement'])) {
            $where[] = "b.placement = ?";
            $params[] = $filters['placement'];
        }
        if (!empty($filters['banner_type'])) {
            $where[] = "b.banner_type = ?";
            $params[] = $filters['banner_type'];
        }
        if (!empty($filters['category'])) {
            $where[] = "b.category = ?";
            $params[] = $filters['category'];
        }
        if (isset($filters['is_active'])) {
            $where[] = "b.is_active = ?";
            $params[] = (int)$filters['is_active'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = "b.user_id = ?";
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['search'])) {
            $searchTerm = \trim((string)$filters['search']);
            $escaped = \addcslashes($searchTerm, '%_');
            $search = "%{$escaped}%";

            $where[] = "(b.title LIKE ? OR b.link LIKE ?)";
            $params[] = $search;
            $params[] = $search;
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'pending') {
                $where[] = "b.banner_type IN ('startup', 'user') AND b.approved_at IS NULL";
            } elseif ($filters['status'] === 'expired') {
                $where[] = "b.end_date IS NOT NULL AND b.end_date < NOW()";
            }
        }

        $sql = "SELECT b.*, u.full_name as user_name, c.full_name as creator_name
                FROM banners b
                LEFT JOIN users u ON b.user_id = u.id
                LEFT JOIN users c ON b.created_by = c.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY b.sort_order ASC, b.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $where = ["deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['placement'])) {
            $where[] = "placement = ?";
            $params[] = $filters['placement'];
        }
        if (!empty($filters['banner_type'])) {
            $where[] = "banner_type = ?";
            $params[] = $filters['banner_type'];
        }
        if (!empty($filters['category'])) {
            $where[] = "category = ?";
            $params[] = $filters['category'];
        }
        if (isset($filters['is_active'])) {
            $where[] = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $sql = "SELECT COUNT(*) as count FROM banners WHERE " . implode(' AND ', $where);
        $result = $this->db->fetch($sql, $params);
        return $result ? (int)$result->count : 0;
    }

    public function getActiveByPlacement(string $placement, int $limit = 5): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->fetchAll(
            "SELECT * FROM banners
             WHERE placement = ? AND is_active = 1 AND deleted_at IS NULL
             AND (start_date IS NULL OR start_date <= ?)
             AND (end_date IS NULL OR end_date >= ?)
             ORDER BY sort_order ASC, RAND() LIMIT ?",
            [$placement, $now, $now, $limit]
        );
    }

    public function create(array $data): ?int
    {
        $fields = [
            'title', 'image_path', 'link', 'placement', 'banner_type', 'category', 'type',
            'custom_code', 'sort_order', 'is_active', 'start_date', 'end_date',
            'duration_days', 'price', 'target', 'alt_text', 'user_id', 'created_by'
        ];

        $insert = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $insert[$field] = $data[$field];
            }
        }
        $insert['created_at'] = date('Y-m-d H:i:s');

        $cols = implode(', ', array_keys($insert));
        $vals = ':' . implode(', :', array_keys($insert));

        $this->db->query("INSERT INTO banners ({$cols}) VALUES ({$vals})", $insert);
        return (int)$this->db->lastInsertId() ?: null;
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'title', 'image_path', 'link', 'placement', 'banner_type', 'category',
            'sort_order', 'is_active', 'start_date', 'end_date', 'duration_days',
            'price', 'target', 'alt_text', 'approved_by', 'approved_at', 'rejection_reason'
        ];

        $sets = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($sets)) return false;

        $sets[] = "updated_at = NOW()";
        return $this->db->query(
            "UPDATE banners SET " . implode(', ', $sets) . " WHERE id = :id AND deleted_at IS NULL",
            $params
        );
    }

    public function approve(int $id, int $adminId): bool
    {
        $banner = $this->find($id);
        if (!$banner || !in_array($banner->banner_type, ['startup', 'user'])) {
            return false;
        }

        $endDate = null;
        if ($banner->duration_days) {
            $endDate = date('Y-m-d H:i:s', strtotime("+{$banner->duration_days} days"));
        }

        return $this->update($id, [
            'is_active' => 1,
            'start_date' => date('Y-m-d H:i:s'),
            'end_date' => $endDate,
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function reject(int $id, string $reason): bool
    {
        return $this->update($id, [
            'is_active' => 0,
            'rejection_reason' => $reason,
        ]);
    }

    public function softDelete(int $id): bool
    {
        return $this->db->query(
            "UPDATE banners SET deleted_at = NOW(), is_active = 0 WHERE id = ?",
            [$id]
        );
    }

    public function incrementImpression(int $id): bool
    {
        return $this->db->query(
            "UPDATE banners SET impressions = impressions + 1,
             ctr = CASE WHEN impressions > 0
                   THEN ROUND((clicks / (impressions + 1)) * 100, 2)
                   ELSE 0 END
             WHERE id = ?",
            [$id]
        );
    }

    public function bulkIncrementImpressions(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return (bool)$this->db->query(
            "UPDATE banners SET impressions = impressions + 1,
             ctr = CASE WHEN impressions > 0
                   THEN ROUND((clicks / (impressions + 1)) * 100, 2)
                   ELSE 0 END
             WHERE id IN ($placeholders)",
            $ids
        );
    }

    public function registerClick(int $id, ?int $userId, string $ip): bool
    {
        try {
            $this->db->beginTransaction();

            // 1. Lock banner row
            $stmt = $this->db->prepare(
                "SELECT id, clicks, impressions FROM banners WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$id]);
            $banner = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$banner) {
                $this->db->rollBack();
                return false;
            }

            // 2. Check duplicate click
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) as count FROM banner_clicks
                 WHERE banner_id = ? AND ip_address = ?
                 AND clicked_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 FOR UPDATE"
            );
            $stmt->execute([$id, $ip]);
            $recentClick = $stmt->fetch(\PDO::FETCH_OBJ);

            if ($recentClick && (int)$recentClick->count > 0) {
                $this->db->rollBack();
                return false;
            }

            // 3. Insert click record
            $stmt = $this->db->prepare(
                "INSERT INTO banner_clicks (banner_id, user_id, ip_address, clicked_at)
                 VALUES (?, ?, ?, NOW())"
            );
            $stmt->execute([$id, $userId, $ip]);

            // 4. Update counters
            $newClicks = (int)$banner->clicks + 1;
            $newCtr = (int)$banner->impressions > 0
                ? \round(($newClicks / (int)$banner->impressions) * 100, 2)
                : 0.0;

            $stmt = $this->db->prepare(
                "UPDATE banners SET clicks = ?, ctr = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([$newClicks, $newCtr, $id]);

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    public function getPending(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT b.*, u.full_name as user_name
             FROM banners b
             LEFT JOIN users u ON b.user_id = u.id
             WHERE b.banner_type IN ('startup', 'user')
             AND b.approved_at IS NULL
             AND b.deleted_at IS NULL
             ORDER BY b.created_at ASC
             LIMIT ?",
            [$limit]
        );
    }

    public function getStats(): array
    {
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM banners WHERE deleted_at IS NULL");
        $active = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM banners WHERE is_active = 1 AND deleted_at IS NULL");
        $pending = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM banners WHERE banner_type IN ('startup', 'user') AND approved_at IS NULL AND deleted_at IS NULL");
        $totalClicks = (int)$this->db->fetchColumn("SELECT COALESCE(SUM(clicks), 0) FROM banners WHERE deleted_at IS NULL");
        $totalImpressions = (int)$this->db->fetchColumn("SELECT COALESCE(SUM(impressions), 0) FROM banners WHERE deleted_at IS NULL");

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'pending' => $pending,
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
        ];
    }
}
