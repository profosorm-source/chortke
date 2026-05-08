<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

class AuditEvent
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

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
            $where .= ' AND at.event = ?';
            $params[] = $event;
        }

        if ($userId) {
            $where .= ' AND at.user_id = ?';
            $params[] = $userId;
        }

        if ($search) {
            $where .= ' AND (at.description LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($dateFrom) {
            $where .= ' AND DATE(at.created_at) >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(at.created_at) <= ?';
            $params[] = $dateTo;
        }

        $events = $this->db->query(
            "SELECT at.*, u.full_name as user_name, u.email as user_email
             FROM audit_trail at
             LEFT JOIN users u ON at.user_id = u.id
             {$where}
             ORDER BY at.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll();

        return $events;
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
            $where .= ' AND event = ?';
            $params[] = $event;
        }

        if ($userId) {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        }

        if ($search) {
            $where .= ' AND (description LIKE ? OR user_id LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($dateFrom) {
            $where .= ' AND DATE(created_at) >= ?';
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $where .= ' AND DATE(created_at) <= ?';
            $params[] = $dateTo;
        }

        $count = $this->db->query(
            "SELECT COUNT(*) as count FROM audit_trail {$where}",
            $params
        )->fetch();

        return (int) ($count['count'] ?? 0);
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
}
