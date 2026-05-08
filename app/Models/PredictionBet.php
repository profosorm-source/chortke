<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class PredictionBet extends Model
{
    protected static string $table = 'prediction_bets';

    // وضعیت‌های مجاز برای شرط
    public const STATUS_PENDING  = 'pending';
    public const STATUS_WON      = 'won';
    public const STATUS_LOST     = 'lost';
    public const STATUS_REFUNDED = 'refunded';

    // ─── ثبت شرط جدید ─────────────────────────────────────────────────
    public function create(array $d): ?object
    {
        $id = $this->db->insert(
            "INSERT INTO prediction_bets
                (user_id, game_id, prediction, amount_usdt, status, created_at)
             VALUES (?, ?, ?, ?, 'pending', NOW())",
            [
                (int)$d['user_id'],
                (int)$d['game_id'],
                (string)$d['prediction'],
                (float)$d['amount_usdt'],
            ]
        );

        if (!$id) {
            return null;
        }

        return $this->db->fetch(
            "SELECT * FROM prediction_bets WHERE id = ?",
            [(int)$id]
        );
    }

    // ─── شرط‌های یک کاربر با اطلاعات بازی ───────────────────────────
    public function getByUser(int $userId, int $limit = 30, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT pb.*,
                    pg.title        AS game_title,
                    pg.team_home,
                    pg.team_away,
                    pg.match_date,
                    pg.result       AS game_result,
                    pg.status       AS game_status,
                    pg.sport_type
             FROM prediction_bets pb
             LEFT JOIN prediction_games pg ON pg.id = pb.game_id
             WHERE pb.user_id = ?
             ORDER BY pb.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    public function countByUser(int $userId): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM prediction_bets WHERE user_id = ?",
            [$userId]
        );
        return (int)($row->cnt ?? 0);
    }

    // ─── همه شرط‌های یک بازی با اطلاعات کاربر ──────────────────────
    public function getByGame(int $gameId): array
    {
        return $this->db->fetchAll(
            "SELECT pb.*,
                    u.full_name,
                    u.email,
                    u.username
             FROM prediction_bets pb
             LEFT JOIN users u ON u.id = pb.user_id
             WHERE pb.game_id = ?
             ORDER BY pb.created_at DESC",
            [$gameId]
        );
    }

    // ─── شرط‌های برنده یک بازی ────────────────────────────────────────
    public function getWinnersByGame(int $gameId, string $winningPrediction): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM prediction_bets
             WHERE game_id = ? AND prediction = ? AND status = 'pending'",
            [$gameId, $winningPrediction]
        );
    }

    // ─── همه شرط‌های فعال یک بازی (برای refund) ─────────────────────
    public function getPendingByGame(int $gameId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM prediction_bets
             WHERE game_id = ? AND status = 'pending'",
            [$gameId]
        );
    }

    // ─── بررسی شرط قبلی (با FOR UPDATE برای استفاده داخل transaction) ──
    public function userHasBetForUpdate(int $userId, int $gameId): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM prediction_bets
             WHERE user_id = ? AND game_id = ?
             LIMIT 1
             FOR UPDATE",
            [$userId, $gameId]
        );

        return $row !== null;
    }

    // ─── بدون قفل (برای نمایش) ────────────────────────────────────────
    public function userHasBet(int $userId, int $gameId): bool
    {
        $row = $this->db->fetch(
            "SELECT id FROM prediction_bets WHERE user_id = ? AND game_id = ? LIMIT 1",
            [$userId, $gameId]
        );

        return $row !== null;
    }

    // ─── بروزرسانی وضعیت با پرداخت ──────────────────────────────────
    public function markWon(int $betId, float $payoutUsdt): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_bets
             SET status = 'won', payout_usdt = ?, settled_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$payoutUsdt, $betId]
        );

        return $affected > 0;
    }

    public function markLost(int $betId): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_bets
             SET status = 'lost', payout_usdt = 0, settled_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$betId]
        );

        return $affected > 0;
    }

    public function markRefunded(int $betId): bool
    {
        $affected = $this->db->execute(
            "UPDATE prediction_bets
             SET status = 'refunded', settled_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$betId]
        );

        return $affected > 0;
    }

    // ─── آمار توزیع شرط‌ها برای یک بازی ─────────────────────────────
    public function getDistribution(int $gameId): object
    {
        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS total_bets,
                COALESCE(SUM(amount_usdt), 0) AS total_pool,
                COALESCE(SUM(CASE WHEN prediction='home' THEN amount_usdt ELSE 0 END), 0) AS pool_home,
                COALESCE(SUM(CASE WHEN prediction='away' THEN amount_usdt ELSE 0 END), 0) AS pool_away,
                COALESCE(SUM(CASE WHEN prediction='draw' THEN amount_usdt ELSE 0 END), 0) AS pool_draw,
                COUNT(CASE WHEN prediction='home' THEN 1 END) AS count_home,
                COUNT(CASE WHEN prediction='away' THEN 1 END) AS count_away,
                COUNT(CASE WHEN prediction='draw' THEN 1 END) AS count_draw
             FROM prediction_bets
             WHERE game_id = ? AND status != 'refunded'",
            [$gameId]
        );

        return $row ?? (object)[
            'total_bets' => 0, 'total_pool' => 0,
            'pool_home'  => 0, 'pool_away'  => 0, 'pool_draw'  => 0,
            'count_home' => 0, 'count_away' => 0, 'count_draw' => 0,
        ];
    }
}
