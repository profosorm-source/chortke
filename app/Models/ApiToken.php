<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Model;

/**
 * ApiToken Model - Secured and Optimized
 */
class ApiToken extends Model
{
    protected static string $table = 'api_tokens';

    public const ALLOWED_SCOPES = [
        'read', 'write', 'admin', 'delete',
        'profile:read', 'profile:write', 
        'wallet:read', 'wallet:write', 
        'transactions:read', 'tasks:read', 'tasks:write',
        'security:read', 'security:write', 
        'settings:read', 'settings:write'
    ];

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    public function findById(int $id): ?array
    {
        $this->validateId($id);

        $token = $this->db->fetch(
            "SELECT at.*, u.full_name, u.email 
             FROM api_tokens at
             LEFT JOIN users u ON u.id = at.user_id
             WHERE at.id = ?",
            [$id]
        );

        return $token ? (array)$token : null;
    }

    public function findAllPaginated(
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $statusFilter = null
    ): array {
        $query = $this->db->table('api_tokens as at')
            ->leftJoin('users as u', 'u.id', '=', 'at.user_id');

        if ($search) {
            $search = $this->escapeLikeValue($search);
            $searchParam = "%{$search}%";
            $query->where(function($q) use ($searchParam) {
                $q->where('at.name', 'LIKE', $searchParam)
                  ->orWhere('u.email', 'LIKE', $searchParam);
            });
        }

        if ($statusFilter === 'active') {
            $query->where('at.revoked', '=', 0)
                  ->where(function($q) {
                      $q->whereNull('at.expires_at')
                        ->orWhere('at.expires_at', '>', date('Y-m-d H:i:s'));
                  });
        } elseif ($statusFilter === 'revoked') {
            $query->where('at.revoked', '=', 1);
        } elseif ($statusFilter === 'expired') {
            $query->where('at.revoked', '=', 0)
                  ->whereNotNull('at.expires_at')
                  ->where('at.expires_at', '<', date('Y-m-d H:i:s'));
        }

        $results = $query->select('at.*', 'u.full_name', 'u.email')
                         ->orderBy('at.created_at', 'DESC')
                         ->limit($limit)
                         ->offset($offset)
                         ->get();

        return $results ?: [];
    }

    public function countAll(?string $search = null, ?string $statusFilter = null): int
    {
        $query = $this->db->table('api_tokens as at')
            ->leftJoin('users as u', 'u.id', '=', 'at.user_id');

        if ($search) {
            $search = $this->escapeLikeValue($search);
            $searchParam = "%{$search}%";
            $query->where(function($q) use ($searchParam) {
                $q->where('at.name', 'LIKE', $searchParam)
                  ->orWhere('u.email', 'LIKE', $searchParam);
            });
        }

        if ($statusFilter === 'active') {
            $query->where('at.revoked', '=', 0)
                  ->where(function($q) {
                      $q->whereNull('at.expires_at')
                        ->orWhere('at.expires_at', '>', date('Y-m-d H:i:s'));
                  });
        } elseif ($statusFilter === 'revoked') {
            $query->where('at.revoked', '=', 1);
        } elseif ($statusFilter === 'expired') {
            $query->where('at.revoked', '=', 0)
                  ->whereNotNull('at.expires_at')
                  ->where('at.expires_at', '<', date('Y-m-d H:i:s'));
        }

        return $query->count();
    }

    public function revokeById(int $id): bool
    {
        $this->validateId($id);

        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE id = ?",
            [$id]
        );

        return true;
    }

    public function revokeForUser(int $id, int $userId): bool
    {
        $this->validateId($id);
        $this->validateId($userId, 'user_id');

        $stmt = $this->db->prepare(
            "UPDATE api_tokens 
             SET revoked = 1, revoked_at = NOW() 
             WHERE id = ? AND user_id = ? AND revoked = 0"
        );
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function revokeByHashForUser(string $plainToken, int $userId): bool
    {
        if (empty($plainToken)) {
            throw new \InvalidArgumentException('Token cannot be empty');
        }
        $this->validateId($userId, 'user_id');

        $details = $this->getHashedTokenDetails($plainToken);
        if (!$details) {
            return false;
        }

        $stmt = $this->db->prepare(
            "UPDATE api_tokens 
             SET revoked = 1, revoked_at = NOW() 
             WHERE token = ? AND secret_version = ? AND user_id = ? AND revoked = 0"
        );
        $stmt->execute([$details['hashed'], $details['version'], $userId]);
        return $stmt->rowCount() > 0;
    }

    public function createToken(
        int $userId, 
        string $plainToken, 
        string $name, 
        string $scopes, 
        ?string $expiresAt
    ): int {
        $this->validateId($userId, 'user_id');

        if (empty($plainToken) || strlen($plainToken) < 32) {
            throw new \InvalidArgumentException('Token too weak');
        }
        
        if (empty($name) || strlen($name) > 100) {
            throw new \InvalidArgumentException('Invalid token name');
        }
        
        $scopesArray = explode(',', $scopes);
        foreach ($scopesArray as $scope) {
            if (!in_array(trim($scope), self::ALLOWED_SCOPES, true)) {
                throw new \InvalidArgumentException("Invalid scope: {$scope}");
            }
        }

        $this->validateDate($expiresAt, 'expires_at');

        $currentVersion = config('security.api.current_secret_version', 'v2');
        $secret = config("security.api.secrets.{$currentVersion}");
        if (empty($secret)) {
            $secret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
        }
        
        if (!$secret || strlen($secret) < 32) {
            throw new \RuntimeException('API secret key is not configured or too weak (minimum 32 characters required)');
        }
        
        $hashedToken = hash_hmac('sha256', $plainToken, $secret);

        $this->db->query(
            "INSERT INTO api_tokens (user_id, token, secret_version, name, scopes, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())",
            [$userId, $hashedToken, $currentVersion, $name, $scopes, $expiresAt]
        );

        return (int)$this->db->lastInsertId();
    }

    public function findByUserId(int $userId): array
    {
        $this->validateId($userId);

        $results = $this->db->fetchAll(
            "SELECT id, name, scopes, last_used_at, use_count, expires_at, created_at
             FROM api_tokens
             WHERE user_id = ? AND revoked = 0
             ORDER BY created_at DESC",
            [$userId]
        );

        return $results ?: [];
    }

    public function countActiveByUserId(int $userId): int
    {
        $this->validateId($userId);

        $count = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens WHERE user_id = ? AND revoked = 0",
            [$userId]
        );

        if (!$count) {
            return 0;
        }

        return (int)(is_array($count) ? ($count['count'] ?? 0) : ($count->count ?? 0));
    }

    public function revokeByHash(string $plainToken): bool
    {
        if (empty($plainToken)) {
            throw new \InvalidArgumentException('Token cannot be empty');
        }

        $details = $this->getHashedTokenDetails($plainToken);
        if (!$details) {
            return false;
        }

        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE token = ? AND secret_version = ?",
            [$details['hashed'], $details['version']]
        );

        return true;
    }

    public function findByHash(string $plainToken): ?array
    {
        if (empty($plainToken)) {
            throw new \InvalidArgumentException('Token cannot be empty');
        }

        $details = $this->getHashedTokenDetails($plainToken);
        return $details ? $details['row'] : null;
    }

    /**
     * Helper to lookup hashed token details iteratively over active secret versions
     */
    private function getHashedTokenDetails(string $plainToken): ?array
    {
        $secrets = config('security.api.secrets', []);
        if (empty($secrets)) {
            $legacySecret = \defined('SECURITY_API_TOKEN_SECRET') ? SECURITY_API_TOKEN_SECRET : null;
            if ($legacySecret) {
                $secrets = ['v2' => $legacySecret];
            }
        }

        $currentVersion = config('security.api.current_secret_version', 'v2');
        
        $orderedSecrets = [];
        if (isset($secrets[$currentVersion])) {
            $orderedSecrets[$currentVersion] = $secrets[$currentVersion];
        }
        foreach ($secrets as $version => $secret) {
            if ($version !== $currentVersion) {
                $orderedSecrets[$version] = $secret;
            }
        }

        foreach ($orderedSecrets as $version => $secret) {
            if (empty($secret) || strlen($secret) < 32) {
                continue;
            }

            $hashedToken = hash_hmac('sha256', $plainToken, $secret);
            
            // Check if this hash and version exists in database
            $tokenRow = $this->db->fetch(
                "SELECT * FROM api_tokens WHERE token = ? AND secret_version = ? LIMIT 1",
                [$hashedToken, $version]
            );

            if ($tokenRow) {
                return [
                    'row' => (array)$tokenRow,
                    'hashed' => $hashedToken,
                    'version' => $version
                ];
            }
        }

        return null;
    }

    public function getStats(): array
    {
        $activeCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens 
             WHERE revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())"
        );

        $revokedCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens WHERE revoked = 1"
        );

        $expiredCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens 
             WHERE revoked = 0 AND expires_at IS NOT NULL AND expires_at < NOW()"
        );

        $usedTodayCount = $this->db->fetch(
            "SELECT COUNT(*) as count FROM api_tokens WHERE DATE(last_used_at) = CURDATE()"
        );

        $active = $activeCount ? (is_array($activeCount) ? ($activeCount['count'] ?? 0) : ($activeCount->count ?? 0)) : 0;
        $revoked = $revokedCount ? (is_array($revokedCount) ? ($revokedCount['count'] ?? 0) : ($revokedCount->count ?? 0)) : 0;
        $expired = $expiredCount ? (is_array($expiredCount) ? ($expiredCount['count'] ?? 0) : ($expiredCount->count ?? 0)) : 0;
        $usedToday = $usedTodayCount ? (is_array($usedTodayCount) ? ($usedTodayCount['count'] ?? 0) : ($usedTodayCount->count ?? 0)) : 0;

        return [
            'active' => (int)$active,
            'revoked' => (int)$revoked,
            'expired' => (int)$expired,
            'used_today' => (int)$usedToday,
        ];
    }
}
