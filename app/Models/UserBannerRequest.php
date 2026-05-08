<?php
namespace App\Models;
use Core\Model;

class UserBannerRequest extends Model
{
    public static function find(int $id): ?object {
        $db = \Core\Database::getInstance()->getConnection();
        $s = $db->prepare("SELECT ubr.*, u.full_name, bp.name as placement_name FROM user_banner_requests ubr LEFT JOIN users u ON u.id=ubr.user_id LEFT JOIN banner_placements bp ON bp.id=ubr.placement_id WHERE ubr.id=?");
        $s->execute([$id]);
        return $s->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    public static function getByUser(int $userId, int $limit = 30, int $offset = 0): array {
        $db = \Core\Database::getInstance()->getConnection();
        $s = $db->prepare("SELECT ubr.*, bp.name as placement_name FROM user_banner_requests ubr LEFT JOIN banner_placements bp ON bp.id=ubr.placement_id WHERE ubr.user_id=? ORDER BY ubr.created_at DESC LIMIT ? OFFSET ?");
        $s->execute([$userId, $limit, $offset]);
        return $s->fetchAll(\PDO::FETCH_OBJ);
    }

    public static function adminList(array $filters = [], int $limit = 30, int $offset = 0): array {
        $db = \Core\Database::getInstance()->getConnection();
        $where = ['1=1']; $params = [];
        if (!empty($filters['status'])) { $where[] = "ubr.status=?"; $params[] = $filters['status']; }
        $params[] = $limit; $params[] = $offset;
        $s = $db->prepare("SELECT ubr.*, u.full_name, u.email, bp.name as placement_name FROM user_banner_requests ubr LEFT JOIN users u ON u.id=ubr.user_id LEFT JOIN banner_placements bp ON bp.id=ubr.placement_id WHERE " . implode(' AND ', $where) . " ORDER BY ubr.created_at DESC LIMIT ? OFFSET ?");
        $s->execute($params);
        return $s->fetchAll(\PDO::FETCH_OBJ);
    }

    public function create(array $d): ?object {
        $db = \Core\Database::getInstance()->getConnection();
        $s = $db->prepare("INSERT INTO user_banner_requests(user_id, placement_id, title, image_path, link_url, days, total_price, status) VALUES(?,?,?,?,?,?,?,'pending')");
        $ok = $s->execute([$d['user_id'], $d['placement_id'] ?? null, $d['title'] ?? null, $d['image_path'] ?? null, $d['link_url'] ?? null, $d['days'] ?? 1, $d['total_price'] ?? 0]);
        return $ok ? $this->find((int) $db->lastInsertId()) : null;
    }

    public static function updateStatus(int $id, string $status, array $extra = []): bool {
        $db = \Core\Database::getInstance()->getConnection();
        $set = ['status=?']; $vals = [$status];
        foreach (['rejection_reason', 'starts_at', 'ends_at'] as $f) {
            if (array_key_exists($f, $extra)) { $set[] = "$f=?"; $vals[] = $extra[$f]; }
        }
        $vals[] = $id;
        return $db->prepare("UPDATE user_banner_requests SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
    }

    public static function statuses(): array {
        return ['pending' => 'در انتظار', 'active' => 'فعال', 'rejected' => 'رد شده', 'cancelled' => 'لغو شده', 'expired' => 'منقضی'];
    }
}
