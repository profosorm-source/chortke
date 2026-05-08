<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class PredictionGame extends Model
{
    protected static string $table = 'prediction_games';

    // ─── Stats subquery — یک‌بار تعریف، همه‌جا استفاده ─────────────────
    private function statsSubquery(): string
    {
        return "
            (SELECT COUNT(*)               FROM prediction_bets pb WHERE pb.game_id = pg.id AND pb.status != 'refunded') AS total_bets,
            (SELECT COALESCE(SUM(pb.amount_usdt),0) FROM prediction_bets pb WHERE pb.game_id = pg.id AND pb.status != 'refunded') AS total_pool,
            (SELECT COALESCE(SUM(pb.amount_usdt),0) FROM prediction_bets pb WHERE pb.game_id = pg.id AND pb.prediction = 'home' AND pb.status != 'refunded') AS pool_home,
            (SELECT COALESCE(SUM(pb.amount_usdt),0) FROM prediction_bets pb WHERE pb.game_id = pg.id AND pb.prediction = 'away' AND pb.status != 'refunded') AS pool_away,
            (SELECT COALESCE(SUM(pb.amount_usdt),0) FROM prediction_bets pb WHERE pb.game_id = pg.id AND pb.prediction = 'draw' AND pb.status != 'refunded') AS pool_draw
        ";
    }

    // ─── Find با آمار کامل ────────────────────────────────────────────
    public function find(int $id): ?object
    {
        return $this->db->fetch(
            "SELECT pg.*, " . $this->statsSubquery() . "
             FROM prediction_games pg
             WHERE pg.id = ? AND pg.deleted_at IS NULL",
            [$id]
        );
    }

    // ─── Create با validation کامل ────────────────────────────────────
    public function create(array $d): ?object
    {
        $id = $this->db->insert(
            "INSERT INTO prediction_games
                (title, team_home, team_away, sport_type, match_date, bet_deadline,
                 min_bet_usdt, max_bet_usdt, commission_percent, status,
                 description, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, NOW())",
            [
                $d['title'],
                $d['team_home'],
                $d['team_away'],
                $d['sport_type']           ?? 'football',
                $d['match_date'],
                $d['bet_deadline'],
                (float)($d['min_bet_usdt'] ?? 1),
                (float)($d['max_bet_usdt'] ?? 1000),
                (float)($d['commission_percent'] ?? 5),
                $d['description']          ?? null,
                (int)$d['created_by'],
            ]
        );

        return $id ? $this->find((int)$id) : null;
    }

    // ─── لیست بازی‌های باز برای کاربران ─────────────────────────────
    public function getOpen(int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT pg.*, " . $this->statsSubquery() . "
             FROM prediction_games pg
             WHERE pg.status = 'open'
               AND pg.bet_deadline > NOW()
               AND pg.deleted_at IS NULL
             ORDER BY pg.match_date ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    // ─── لیست ادمین با فیلتر ─────────────────────────────────────────
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where  = ['pg.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'pg.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['sport_type'])) {
            $where[]  = 'pg.sport_type = ?';
            $params[] = $filters['sport_type'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(pg.title LIKE ? OR pg.team_home LIKE ? OR pg.team_away LIKE ?)';
            $s        = '%' . $filters['search'] . '%';
            $params[] = $s;
            $params[] = $s;
            $params[] = $s;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll(
            "SELECT pg.*, " . $this->statsSubquery() . "
             FROM prediction_games pg
             WHERE " . implode(' AND ', $where) . "
             ORDER BY pg.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    public function adminCount(array $filters = []): int
    {
        $where  = ['deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }

        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM prediction_games WHERE " . implode(' AND ', $where),
            $params
        );

        return (int)($row->cnt ?? 0);
    }

    // ─── ثبت نتیجه و بستن بازی برای شرط‌های جدید ────────────────────
    public function setResult(int $id, string $result): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games
             SET result = ?, status = 'finished', finished_at = NOW()
             WHERE id = ? AND status IN ('open','closed') AND deleted_at IS NULL",
            [$result, $id]
        );

        return $affected > 0;
    }

    // ─── بستن بازی (توقف شرط‌های جدید) ──────────────────────────────
    public function closeBetting(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games SET status = 'closed'
             WHERE id = ? AND status = 'open' AND deleted_at IS NULL",
            [$id]
        );

        return $affected > 0;
    }

    // ─── لغو بازی ────────────────────────────────────────────────────
    public function cancel(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games
             SET status = 'cancelled', cancelled_at = NOW()
             WHERE id = ? AND status IN ('open','closed') AND deleted_at IS NULL",
            [$id]
        );

        return $affected > 0;
    }

    // ─── علامت پرداخت برندگان ─────────────────────────────────────────
    public function markWinnersPaid(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games SET winners_paid = 1, paid_at = NOW()
             WHERE id = ? AND status = 'finished' AND winners_paid = 0",
            [$id]
        );

        return $affected > 0;
    }

    // ─── Soft delete ──────────────────────────────────────────────────
    public function softDelete(int $id): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_games SET deleted_at = NOW()
             WHERE id = ? AND status IN ('finished','cancelled') AND deleted_at IS NULL",
            [$id]
        );

        return $affected > 0;
    }
}
