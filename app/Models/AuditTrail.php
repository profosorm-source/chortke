<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class AuditTrail extends Model
{
    protected static string $table = 'audit_trail';

    public const SYSTEM_ACTOR_ID = 0; // 🚀 M-07: ID for system/cron actions

    public function createEntry(array $data)
    {
        // 🚀 M-07 Fix: Ensure attribution for system actions
        if (!isset($data['actor_id']) && !isset($data['user_id'])) {
            $data['actor_id'] = self::SYSTEM_ACTOR_ID;
        }

        // Fetch the hash of the immediately preceding audit log row
        $lastRow = $this->db->fetch("SELECT hash FROM " . static::$table . " ORDER BY id DESC LIMIT 1");
        $prevHash = $lastRow ? $lastRow->hash : null;
        
        $genesisHash = str_repeat('0', 64);
        $effectivePrevHash = $prevHash ?? $genesisHash;

        // Construct current payload to hash
        $payload = json_encode([
            'request_id' => $data['request_id'] ?? null,
            'event' => $data['event'] ?? '',
            'user_id' => $data['user_id'] ?? null,
            'actor_id' => $data['actor_id'] ?? self::SYSTEM_ACTOR_ID,
            'context' => $data['context'] ?? '{}',
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
            'created_at' => $data['created_at'] ?? date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $hash = hash('sha256', $effectivePrevHash . '|' . $payload);

        $data['prev_hash'] = $prevHash;
        $data['hash'] = $hash;

        return $this->create($data);
    }

    public function getForUser(int $userId, int $limit = 50): array
    {
        // 🚀 L-04 Fix: Max cap for limit
        $limit = min($limit, 500);
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
        // 🚀 L-04 Fix: Max cap for perPage
        $perPage = min(max(1, $perPage), 100);
        $params = [];
        $where = $this->buildAuditFilters($event, $userId, $search, $dateFrom, $dateTo, $params);

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

        // M17: Use named parameters consistently to avoid mixing with positional
        $namedParams = $params;
        $namedParams['limit'] = $perPage;
        $namedParams['offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($namedParams);
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

    public function deleteOlderThan(string $cutoff, int $limit = 5000, bool $bypassCompliance = false): int
    {
        // 🚀 BUG FIX [C-03]: Audit logs must be immutable. Physical deletion is prohibited.
        // جایگزینی با سیستم آرشیو به Cold Storage در آینده
        if (!$bypassCompliance) {
            throw new \RuntimeException("Physical deletion of audit logs is prohibited for security compliance. Use archival instead.");
        }

        $limit = (int)$limit;
        $stmt = $this->db->prepare("DELETE FROM " . static::$table . " WHERE created_at < ? LIMIT {$limit}");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    public function cleanupOlderThan(int $days = 365, bool $bypassCompliance = false): int
    {
        // 🚀 BUG FIX [C-03]: Prevent automated cleanup via physical delete
        if (!$bypassCompliance) {
            throw new \RuntimeException("Automated physical cleanup is disabled. Use archival processes.");
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        return $this->deleteOlderThan($cutoff, 500000, true);
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
            $where .= ' AND at.event = :event';
            $params['event'] = $event;
        }

        if ($userId !== null) {
            $where .= ' AND (at.user_id = :user_id OR at.actor_id = :actor_id)';
            $params['user_id'] = $userId;
            $params['actor_id'] = $userId;
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $where .= ' AND at.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where .= ' AND at.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        if ($search !== null && $search !== '') {
            $searchTerm = \trim((string)$search);
            $escaped = \addcslashes($searchTerm, '%_\\');
            $like = "%{$escaped}%";
            $where .= ' AND (at.event LIKE :search_event OR at.context LIKE :search_context OR u.email LIKE :search_email)';
            $params['search_event'] = $like;
            $params['search_context'] = $like;
            $params['search_email'] = $like;
        }

        return $where;
    }

    private function fetchCount(string $sql, array $params = []): int
    {
        return (int)$this->db->fetchColumn($sql, $params);
    }

    public function update(int $id, array $data): bool
    {
        throw new \RuntimeException("Audit logs are strictly immutable and cannot be updated.");
    }

    public function delete(int $id): bool
    {
        throw new \RuntimeException("Physical deletion of audit logs is prohibited for compliance.");
    }
}

