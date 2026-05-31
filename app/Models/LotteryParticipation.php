<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class LotteryParticipation extends Model {
    protected static string $table = 'lottery_participations';
    public const MIN_CHANCE = 5.0;
    public const DEFAULT_CHANCE = 100.0;
    public const BASE_REWARD = 2.5;
    public const BASE_PENALTY = 1.8;
    public const DECAY_FACTOR = 0.995;
/**
     * ایجاد مشارکت
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;
        $data['is_deleted'] = $data['is_deleted'] ?? 0;
        $data['status'] = $data['status'] ?? 'active';
        $data['chance_score'] = $data['chance_score'] ?? self::DEFAULT_CHANCE;

        $columns = \array_keys($data);
        $values  = \array_values($data);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `lottery_participations` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

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
            "SELECT * FROM lottery_participations WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function findByUserAndRound(int $userId, int $roundId): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM lottery_participations
             WHERE user_id = ? AND round_id = ? AND is_deleted = 0
             LIMIT 1",
            [$userId, $roundId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function isParticipating(int $userId, int $roundId): bool
    {
        return $this->findByUserAndRound($userId, $roundId) !== null;
    }

    public function getByRound(int $roundId, int $limit = 100, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $stmt = $this->db->prepare(
            "SELECT lp.*, u.full_name as user_name
             FROM lottery_participations lp
             JOIN users u ON lp.user_id = u.id
             WHERE lp.round_id = ? AND lp.is_deleted = 0
             ORDER BY lp.chance_score DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $roundId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function countByRound(int $roundId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total FROM lottery_participations
             WHERE round_id = ? AND is_deleted = 0",
            [$roundId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0);
    }

    /**
     * دریافت آمار مشارکت چندین دوره به صورت یکجا (جلوگیری از N+1)
     */
    public function getCountsByRounds(array $roundIds): array
    {
        if (empty($roundIds)) {
            return [];
        }

        $placeholders = \implode(',', \array_fill(0, \count($roundIds), '?'));
        $stmt = $this->db->query(
            "SELECT round_id, COUNT(*) as total 
             FROM lottery_participations
             WHERE round_id IN ({$placeholders}) AND is_deleted = 0
             GROUP BY round_id",
            $roundIds
        );

        $results = $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
        $counts = [];
        foreach ($results as $row) {
            $counts[(int)$row->round_id] = (int)$row->total;
        }
        return $counts;
    }

    public function getAllActiveByRound(int $roundId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM lottery_participations
             WHERE round_id = ? AND status = 'active' AND is_deleted = 0
             ORDER BY chance_score DESC",
            [$roundId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function getTotalChanceScore(int $roundId): float
    {
        $stmt = $this->db->query(
            "SELECT COALESCE(SUM(chance_score), 0) as total
             FROM lottery_participations
             WHERE round_id = ? AND status = 'active' AND is_deleted = 0",
            [$roundId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (float)($row->total ?? 0);
    }

    /**
     * آمار توزیع شانس (برای نمایش عمومی)
     */
    public function getChanceDistribution(int $roundId): array
    {
        $all = $this->getAllActiveByRound($roundId);

        $high = 0;
        $medium = 0;
        $low = 0;

        foreach ($all as $p) {
            $score = (float)($p->chance_score ?? 0);
            if ($score >= 80) $high++;
            elseif ($score >= 40) $medium++;
            else $low++;
        }

        return [
            'high' => $high,
            'medium' => $medium,
            'low' => $low,
            'total' => \count($all),
        ];
    }

    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = \date('Y-m-d H:i:s');

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            $fields[] = "`{$k}` = ?";
            $values[] = $v;
        }

        $values[] = $id;

        $sql = "UPDATE lottery_participations
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    public function getByUser(int $userId, int $limit = 20): array
    {
        $limit = \max(1, (int)$limit);

        $stmt = $this->db->prepare(
            "SELECT lp.*, lr.title as round_title, lr.status as round_status, lr.prize_amount
             FROM lottery_participations lp
             JOIN lottery_rounds lr ON lp.round_id = lr.id
             WHERE lp.user_id = ? AND lp.is_deleted = 0
             ORDER BY lp.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
}