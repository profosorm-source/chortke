<?php

namespace App\Models;

use Core\Database;
use Core\Model;

class BannerPlacement extends Model
{
    protected static string $table = 'banner_placements';

    public function all($filters = [], $limit = 100, $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['page'])) {
            $where[] = "(page = ? OR page = 'all')";
            $params[] = $filters['page'];
        }
        if (isset($filters['is_active'])) {
            $where[] = "is_active = ?";
            $params[] = (int)$filters['is_active'];
        }

        $sql = "SELECT * FROM banner_placements WHERE " . implode(' AND ', $where) . 
               " ORDER BY page ASC, position ASC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($sql, $params);
    }

    public function find(int $id): ?object
    {
        return $this->db->fetch("SELECT * FROM banner_placements WHERE id = ?", [$id]);
    }

    public function findBySlug(string $slug): ?object
    {
        return $this->db->fetch("SELECT * FROM banner_placements WHERE slug = ?", [$slug]);
    }

    public function update(int $id, array $data): bool
    {
        $allowed = [
            'title', 'description', 'is_active', 'show_on_mobile', 'show_on_desktop',
            'max_banners', 'rotation_speed', 'display_style', 'auto_rotate',
            'max_width', 'max_height'
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

        return $this->db->query(
            "UPDATE banner_placements SET " . implode(', ', $sets) . " WHERE id = :id",
            $params
        );
    }

    public function allWithBannerCount(): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->fetchAll(
            "SELECT bp.*,
                    (SELECT COUNT(*) FROM banners b
                     WHERE b.placement = bp.slug AND b.is_active = 1 AND b.deleted_at IS NULL
                     AND (b.start_date IS NULL OR b.start_date <= ?)
                     AND (b.end_date IS NULL OR b.end_date >= ?)) as active_banners
             FROM banner_placements bp
             ORDER BY bp.page ASC, bp.position ASC",
            [$now, $now]
        );
    }
}
