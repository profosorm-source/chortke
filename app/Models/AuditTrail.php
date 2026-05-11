<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class AuditTrail extends Model
{
    protected static string $table = 'audit_trail';

    public function createEntry(array $data)
    {
        return $this->create($data);
    }

    public function getForUser(int $userId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM " . static::$table . "
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$userId, $limit]
        ) ?: [];
    }

    public function getAll(
        int $page = 1,
        int $perPage = 50,
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $params = [];
        $where = $this->buildAuditFilters($event, $userId, $search, $dateFrom, $dateTo, $params);

        $perPage = \max(1, $perPage);
        $offset = \max(0, ($page - 1) * $perPage);

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*)
             FROM " . static::$table . " at
             LEFT JOIN users u ON u.id = at.user_id
             {$where}",
            $params
        );

        $sql = "SELECT at.*,
                       u.full_name AS user_name, u.email AS user_email,
                       a.full_name AS actor_name, a.email AS actor_email
                FROM " . static::$table . " at
                LEFT JOIN users u ON u.id = at.user_id
                LEFT JOIN users a ON a.id = at.actor_id
                {$where}
                ORDER BY at.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        for ($i = 0; $i < \count($params); $i++) {
            $stmt->bindValue($i + 1, $params[$i]);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'totalPages' => (int)ceil($total / max($perPage, 1)),
        ];
    }

    public function getEventTypes(): array
    {
        return $this->db->fetchAll(
            "SELECT event, COUNT(*) AS total
             FROM " . static::$table . "
             GROUP BY event
             ORDER BY total DESC, event ASC"
        ) ?: [];
    }

    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $params = [];
        $where = 'WHERE 1=1';

        if (!empty($dateFrom)) {
            $where .= ' AND at.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if (!empty($dateTo)) {
            $where .= ' AND at.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $total = $this->fetchCount("SELECT COUNT(*) FROM " . static::$table . " at {$where}", $params);

        $uniqueUsers = $this->fetchCount(
            "SELECT COUNT(DISTINCT at.user_id)
             FROM " . static::$table . " at
             {$where} AND at.user_id IS NOT NULL",
            $params
        );

        $uniqueActors = $this->fetchCount(
            "SELECT COUNT(DISTINCT at.actor_id)
             FROM " . static::$table . " at
             {$where} AND at.actor_id IS NOT NULL",
            $params
        );

        $today = $this->fetchCount(
            "SELECT COUNT(*)
             FROM " . static::$table . " at
             {$where} AND DATE(at.created_at) = CURDATE()",
            $params
        );

        return [
            'total' => $total,
            'unique_users' => $uniqueUsers,
            'unique_actors' => $uniqueActors,
            'today' => $today,
        ];
    }

    public function fetchBatchOlderThan(string $cutoff, int $lastId, int $chunkSize): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM " . static::$table . "
             WHERE created_at < ? AND id > ?
             ORDER BY id ASC
             LIMIT ?",
            [$cutoff, $lastId, $chunkSize]
        ) ?: [];
    }

    public function deleteOlderThan(string $cutoff, int $limit = 5000): int
    {
        $totalDeleted = 0;
        $chunkSize = 1000;
        $remaining = $limit;

        try {
            while ($remaining > 0) {
                $this->db->beginTransaction();

                $currentLimit = \min($chunkSize, $remaining);
                $stmt = $this->db->prepare(
                    "DELETE FROM " . static::$table . "
                     WHERE created_at < ?
                     LIMIT :limit"
                );
                $stmt->bindValue(1, $cutoff);
                $stmt->bindValue(':limit', $currentLimit, \PDO::PARAM_INT);
                $stmt->execute();

                $deleted = $stmt->rowCount();
                $this->db->commit();

                $totalDeleted += $deleted;
                $remaining -= $currentLimit;

                if ($deleted < $currentLimit) {
                    break;
                }
            }
            return $totalDeleted;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $totalDeleted;
        }
    }

    public function cleanupOlderThan(int $days = 365): int
    {
        $cutoff = date('Y-m-d H:i:s', \strtotime("-{$days} days"));
        return $this->deleteOlderThan($cutoff, 5000);
    }

    private function buildAuditFilters(
        ?string $event,
        ?int $userId,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        array &$params
    ): string {
        $where = 'WHERE 1=1';

        if ($event !== null && $event !== '') {
            $where .= ' AND at.event = ?';
            $params[] = $event;
        }

        if ($userId !== null) {
            $where .= ' AND (at.user_id = ? OR at.actor_id = ?)';
            $params[] = $userId;
            $params[] = $userId;
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $where .= ' AND at.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where .= ' AND at.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        if ($search !== null && $search !== '') {
            $searchTerm = \trim((string)$search);
            $escaped = \addcslashes($searchTerm, '%_');
            $like = "%{$escaped}%";
            $where .= ' AND (at.event LIKE ? OR at.context LIKE ? OR u.email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        return $where;
    }

    private function fetchCount(string $sql, array $params = []): int
    {
        return (int)$this->db->fetchColumn($sql, $params);
    }
}
