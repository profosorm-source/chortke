<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

class ApiToken
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        $token = $this->db->query(
            "SELECT at.*, u.full_name, u.email 
             FROM api_tokens at
             LEFT JOIN users u ON u.id = at.user_id
             WHERE at.id = ?",
            [$id]
        )->fetch();

        return $token ?: null;
    }

    public function findAllPaginated(
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $statusFilter = null
    ): array {
        $where = 'WHERE 1=1';
        $params = [];

        if ($search) {
            $where .= ' AND (at.name LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($statusFilter === 'active') {
            $where .= ' AND at.revoked = 0 AND (at.expires_at IS NULL OR at.expires_at > NOW())';
        } elseif ($statusFilter === 'revoked') {
            $where .= ' AND at.revoked = 1';
        } elseif ($statusFilter === 'expired') {
            $where .= ' AND at.revoked = 0 AND at.expires_at < NOW()';
        }

        $tokens = $this->db->query(
            "SELECT at.*, u.full_name, u.email FROM api_tokens at
             LEFT JOIN users u ON u.id = at.user_id {$where}
             ORDER BY at.created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll();

        return $tokens;
    }

    public function countAll(?string $search = null, ?string $statusFilter = null): int
    {
        $where = 'WHERE 1=1';
        $params = [];

        if ($search) {
            $where .= ' AND (at.name LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        if ($statusFilter === 'active') {
            $where .= ' AND at.revoked = 0 AND (at.expires_at IS NULL OR at.expires_at > NOW())';
        } elseif ($statusFilter === 'revoked') {
            $where .= ' AND at.revoked = 1';
        } elseif ($statusFilter === 'expired') {
            $where .= ' AND at.revoked = 0 AND at.expires_at < NOW()';
        }

        $count = $this->db->query(
            "SELECT COUNT(*) as count FROM api_tokens at LEFT JOIN users u ON u.id = at.user_id {$where}",
            $params
        )->fetch();

        return (int) ($count['count'] ?? 0);
    }

    public function revokeById(int $id): bool
    {
        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE id = ?",
            [$id]
        );

        return true;
    }

    public function createToken(int $userId, string $token, string $name, string $scopes, string $expiresAt): int
    {
        $this->db->query(
            "INSERT INTO api_tokens (user_id, token, name, scopes, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$userId, $token, $name, $scopes, $expiresAt]
        );
        return (int)$this->db->lastInsertId();
    }

    public function findByUserId(int $userId): array
    {
        return $this->db->query(
            "SELECT id, name, scopes, last_used_at, use_count, expires_at, created_at
             FROM api_tokens
             WHERE user_id = ? AND revoked = 0
             ORDER BY created_at DESC",
            [$userId]
        )->fetchAll();
    }

    public function countActiveByUserId(int $userId): int
    {
        $count = $this->db->query(
            "SELECT COUNT(*) as count FROM api_tokens WHERE user_id = ? AND revoked = 0",
            [$userId]
        )->fetch();

        return (int) ($count['count'] ?? 0);
    }

    public function revokeByHash(string $hashedToken): bool
    {
        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE token = ?",
            [$hashedToken]
        );

        return true;
    }

    public function findByHash(string $hashedToken): ?array
    {
        $token = $this->db->query(
            "SELECT * FROM api_tokens WHERE token = ? LIMIT 1",
            [$hashedToken]
        )->fetch();

        return $token ? (array)$token : null;
    }

    public function getStats(): array
    {
        $activeCount = $this->db->query(
            "SELECT COUNT(*) as count FROM api_tokens 
             WHERE revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())"
        )->fetch();

        $revokedCount = $this->db->query(
            "SELECT COUNT(*) as count FROM api_tokens WHERE revoked = 1"
        )->fetch();

        $expiredCount = $this->db->query(
            "SELECT COUNT(*) as count FROM api_tokens 
             WHERE revoked = 0 AND expires_at < NOW()"
        )->fetch();

        $usedTodayCount = $this->db->query(
            "SELECT COUNT(*) as count FROM api_tokens WHERE DATE(last_used_at) = CURDATE()"
        )->fetch();

        return [
            'active' => (int) ($activeCount['count'] ?? 0),
            'revoked' => (int) ($revokedCount['count'] ?? 0),
            'expired' => (int) ($expiredCount['count'] ?? 0),
            'used_today' => (int) ($usedTodayCount['count'] ?? 0),
        ];
    }
}
