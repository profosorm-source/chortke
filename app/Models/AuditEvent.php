<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

// L-05: Fixed inheritance - AuditEvent should extend Model like other models
class AuditEvent extends Model
{
    protected static string $table = 'audit_trail';

    public function findById(int $id): ?array
    {
        $event = $this->db->query(
            "SELECT at.*, u.full_name as user_name, u.email as user_email,
                    a.full_name as actor_name, a.email as actor_email
             FROM audit_trail at
             LEFT JOIN users u ON at.user_id = u.id
             LEFT JOIN users a ON at.actor_id = a.id
             WHERE at.id = ?",
            [$id]
        )->fetch();

        return $event ?: null;
    }

    public function findAllPaginated(
        int $limit,
        int $offset,
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $where = 'WHERE 1=1';
        $params = [];

        if ($event) {
            $where .= ' AND at.event = :event';
            $params['event'] = $event;
        }

        if ($userId) {
            $where .= ' AND at.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        if ($search) {
            $where .= ' AND (at.description LIKE :search OR u.email LIKE :search)';
            $params['search'] = "%{$search}%";
        }

        if ($dateFrom) {
            $where .= ' AND DATE(at.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(at.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT at.*, u.full_name as user_name, u.email as user_email
                FROM audit_trail at
                LEFT JOIN users u ON at.user_id = u.id
                {$where}
                ORDER BY at.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function countAll(
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): int {
        $where = 'WHERE 1=1';
        $params = [];

        if ($event) {
            $where .= ' AND at.event = :event';
            $params['event'] = $event;
        }

        if ($userId) {
            $where .= ' AND at.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        if ($search) {
            $escaped = addcslashes($search, '%_\\');
            $where .= ' AND (at.description LIKE :search OR u.email LIKE :search)';
            $params['search'] = "%{$escaped}%";
        }

        if ($dateFrom) {
            $where .= ' AND DATE(at.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(at.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = "SELECT COUNT(*) as count 
                FROM audit_trail at
                LEFT JOIN users u ON at.user_id = u.id
                {$where}";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();

        return (int) ($row['count'] ?? 0);
    }

    public function getEventTypes(): array
    {
        $types = $this->db->query(
            "SELECT DISTINCT event FROM audit_trail ORDER BY event ASC"
        )->fetchAll();

        return array_column($types, 'event');
    }

    public function getStats(string $dateFrom = null, string $dateTo = null): array
    {
        $where = '1=1';
        $params = [];

        if ($dateFrom) {
            $where .= ' AND DATE(created_at) >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(created_at) <= ?';
            $params[] = $dateTo;
        }

        $stats = $this->db->query(
            "SELECT 
                COUNT(*) as total_events,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT event) as unique_events,
                MIN(created_at) as earliest,
                MAX(created_at) as latest
             FROM audit_trail WHERE {$where}",
            $params
        )->fetch();

        return $stats ?: [];
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

