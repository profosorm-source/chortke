<?php

namespace App\Models;

use Core\Model;

class LotteryVote extends Model
{
    protected static string $table = 'lottery_votes';

    /**
     * ثبت رأی
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        if (!isset($data['is_deleted'])) {
            $data['is_deleted'] = 0;
        }

        $columns = \array_keys($data);
        $values  = \array_values($data);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `lottery_votes` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    /**
     * ثبت رأی تحت تراکنش برای حل همزمانی و جلوگیری از رأی تکراری در یک روز
     */
    public function createWithTransaction(array $data): ?int
    {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM lottery_votes
                WHERE user_id = ? AND daily_number_id = ? AND is_deleted = 0
                FOR UPDATE
            ");
            $stmt->execute([$data['user_id'], $data['daily_number_id']]);
            $voted = (int)$stmt->fetchColumn() > 0;

            if ($voted) {
                $this->db->rollBack();
                return null;
            }

            $id = $this->create($data);
            if ($id) {
                $this->db->commit();
                return $id;
            }

            $this->db->rollBack();
            return null;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return null;
        }
    }

    public function hasVotedToday(int $userId, int $dailyNumberId): bool
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total
             FROM lottery_votes
             WHERE user_id = ? AND daily_number_id = ? AND is_deleted = 0",
            [$userId, $dailyNumberId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0) > 0;
    }

    public function getVoteCounts(int $dailyNumberId): array
    {
        $stmt = $this->db->query(
            "SELECT voted_number, COUNT(*) as vote_count
             FROM lottery_votes
             WHERE daily_number_id = ? AND is_deleted = 0
             GROUP BY voted_number
             ORDER BY vote_count DESC",
            [$dailyNumberId]
        );

        $results = $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];

        $counts = [];
        foreach ($results as $r) {
            $counts[(string)$r->voted_number] = (int)$r->vote_count;
        }

        return $counts;
    }

    public function getTotalVotes(int $dailyNumberId): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total
             FROM lottery_votes
             WHERE daily_number_id = ? AND is_deleted = 0",
            [$dailyNumberId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0);
    }

    public function getUserVote(int $userId, int $dailyNumberId): ?object
    {
        $stmt = $this->db->query(
            "SELECT *
             FROM lottery_votes
             WHERE user_id = ? AND daily_number_id = ? AND is_deleted = 0
             ORDER BY id DESC
             LIMIT 1",
            [$userId, $dailyNumberId]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }
}