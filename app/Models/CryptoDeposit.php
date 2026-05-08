<?php

namespace App\Models;

use Core\Model;

class CryptoDeposit extends Model
{
    protected static string $table = 'crypto_deposits';

    // ⚠️ create را override نکن (چون Core\Model::create static است)
    // اگر نیاز به دستکاری داده قبل از create داری، متد جدا بساز.

    public function findByHash(string $txHash): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE tx_hash = :tx_hash LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tx_hash' => $txHash]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

public function getManualReviewDeposits(int $limit = 50, int $offset = 0): array
{
    $limit  = \max(1, (int)$limit);
    $offset = \max(0, (int)$offset);

    $sql = "SELECT d.*, u.full_name, u.email
            FROM " . static::$table . " d
            LEFT JOIN users u ON d.user_id = u.id
            WHERE d.verification_status = 'manual_review'
            ORDER BY d.created_at ASC
            LIMIT {$limit} OFFSET {$offset}";

    $stmt = $this->db->prepare($sql);
    $stmt->execute();

    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}



public function getAll(?string $status = null, ?string $network = null, int $limit = 50, int $offset = 0): array
{
    $limit  = \max(1, (int)$limit);
    $offset = \max(0, (int)$offset);

    $sql = "SELECT d.*, u.full_name, u.email
            FROM " . static::$table . " d
            LEFT JOIN users u ON d.user_id = u.id
            WHERE 1=1";

    $params = [];

    if ($status) {
        $sql .= " AND d.verification_status = :status";
        $params['status'] = $status;
    }

    if ($network) {
        $sql .= " AND d.network = :network";
        $params['network'] = $network;
    }

    $sql .= " ORDER BY d.created_at DESC LIMIT {$limit} OFFSET {$offset}";

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(\PDO::FETCH_OBJ);
}

public function countAll(?string $status = null, ?string $network = null): int
{
    $sql = "SELECT COUNT(*) as count
            FROM " . static::$table . "
            WHERE 1=1";

    $params = [];

    if ($status) {
        $sql .= " AND verification_status = :status";
        $params['status'] = $status;
    }

    if ($network) {
        $sql .= " AND network = :network";
        $params['network'] = $network;
    }

    $stmt = $this->db->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch(\PDO::FETCH_OBJ);
    return (int)($row->count ?? 0);
}

public function countManualReview(): int
{
    $sql = "SELECT COUNT(*) as count
            FROM " . static::$table . "
            WHERE verification_status = 'manual_review'";

    $stmt = $this->db->prepare($sql);
    $stmt->execute();

    $row = $stmt->fetch(\PDO::FETCH_OBJ);
    return (int)($row->count ?? 0);
}

    public function getUserDeposits(int $userId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT * FROM " . static::$table . " WHERE user_id = :user_id";
        $params = ['user_id' => $userId];

        if ($status) {
            $sql .= " AND verification_status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function hasPendingDeposit(int $userId): bool
    {
        $sql = "SELECT COUNT(*) as count
                FROM " . static::$table . "
                WHERE user_id = :user_id
                AND verification_status IN ('pending','manual_review')";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        return ((int)($row->count ?? 0)) > 0;
    }

    public function updateStatus(
        int $id,
        string $status,
        ?array $explorerData = null,
        ?string $rejectionReason = null,
        ?int $reviewedBy = null,
        ?string $transactionId = null
    ): bool {
        $sql = "UPDATE " . static::$table . " SET verification_status = :status, updated_at = NOW()";
        $params = ['id' => $id, 'status' => $status];

        if ($status === 'auto_verified') {
            $sql .= ", auto_verified_at = NOW()";
        }

        if ($explorerData) {
            $sql .= ", explorer_data = :explorer_data";
            $params['explorer_data'] = \json_encode($explorerData, JSON_UNESCAPED_UNICODE);
        }

        if ($rejectionReason) {
            $sql .= ", rejection_reason = :rejection_reason";
            $params['rejection_reason'] = $rejectionReason;
        }

        if ($reviewedBy) {
            $sql .= ", reviewed_by = :reviewed_by, reviewed_at = NOW()";
            $params['reviewed_by'] = $reviewedBy;
        }

        if ($transactionId) {
            $sql .= ", transaction_id = :transaction_id";
            $params['transaction_id'] = $transactionId;
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function incrementAttempts(int $id): bool
    {
        $sql = "UPDATE " . static::$table . "
                SET verification_attempts = verification_attempts + 1, updated_at = NOW()
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function getPendingRecent(int $hours = 12, int $limit = 10): array
    {
        $sql = "SELECT id FROM " . static::$table . "
                WHERE status = 'pending'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY created_at ASC
                LIMIT ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$hours, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getExpiredPending(int $minutes = 30, int $minAttempts = 3): array
    {
        $sql = "SELECT * FROM " . static::$table . "
                WHERE verification_status = 'pending'
                  AND verification_attempts >= ?
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$minAttempts, $minutes]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
}
