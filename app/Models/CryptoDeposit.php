<?php

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

class CryptoDeposit extends Model
{
    use Filterable;

    protected static string $table = 'crypto_deposits';
    protected static array $searchable = ['d.tx_hash'];

    protected static array $filterable = [
        'status' => ['d.verification_status', '='],
        'network' => ['d.network', '='],
        'user_id' => ['d.user_id', '='],
    ];

    protected ?\App\Contracts\LoggerInterface $logger;

    public function __construct(\Core\Database $db, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct($db);
        $this->logger = $logger;
    }

    public function findByHash(string $txHash): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE tx_hash = :tx_hash LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tx_hash' => $txHash]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public function findByHashForUpdate(string $txHash): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE tx_hash = :tx_hash LIMIT 1 FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tx_hash' => $txHash]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public function findByHashAndNetwork(string $txHash, string $network): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE tx_hash = :tx_hash AND network = :network LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tx_hash' => $txHash, 'network' => $network]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public function findByHashAndNetworkForUpdate(string $txHash, string $network): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE tx_hash = :tx_hash AND network = :network LIMIT 1 FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tx_hash' => $txHash, 'network' => $network]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public function getManualReviewDeposits(int $limit = 50, int $offset = 0): array
    {
        $limit  = \min(\max(1, (int)$limit), 100);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT d.*, u.full_name, u.email
                FROM " . static::$table . " d FORCE INDEX (idx_status_created)
                LEFT JOIN users u ON d.user_id = u.id
                WHERE d.verification_status = 'manual_review'
                ORDER BY d.created_at ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function getAll(?string $status = null, ?string $network = null, int $limit = 50, int $offset = 0): array
    {
        $limit  = \min(\max(1, (int)$limit), 100);
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

        $sql .= " ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

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

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            if ($key === 'user_id') {
                $stmt->bindValue(':' . $key, $val, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, $val);
            }
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

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
        try {
            $inTx = $this->db->inTransaction();
            if (!$inTx) {
                $this->db->beginTransaction();
            }

            // 1. Get deposit details with FOR UPDATE lock
            $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $deposit = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$deposit) {
                if (!$inTx) {
                    $this->db->rollBack();
                }
                throw new \RuntimeException("Crypto deposit ID {$id} not found.");
            }

            // Enforce strict state-machine transitions directly within the Model (C-13)
            $allowedTransitions = [
                'pending' => ['auto_verified', 'manual_review', 'rejected'],
                'manual_review' => ['verified', 'rejected'],
                'auto_verified' => [],
                'verified' => [],
                'rejected' => [],
            ];

            $currentStatus = $deposit->verification_status ?? 'pending';
            if ($currentStatus !== $status && !in_array($status, $allowedTransitions[$currentStatus] ?? [])) {
                if (!$inTx) {
                    $this->db->rollBack();
                }
                throw new \RuntimeException("State transition from '{$currentStatus}' to '{$status}' is not allowed.");
            }

            // M27+M28: UPDATE status only - FINANCIAL LOGIC MUST MOVE TO CryptoDepositService
            // Service responsibility: wallet updates, transaction creation, validation
            // Model responsibility: persistence only
            
            $sql = "UPDATE " . static::$table . " SET verification_status = :status, updated_at = NOW()";
            $params = ['id' => $id, 'status' => $status];

            if ($status === 'verified' || $status === 'auto_verified') {
                $sql .= ", verified_at = NOW()";
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
            $stmt->execute($params);

            if (!$inTx) {
                $this->db->commit();
            }
            return true;

        } catch (\Throwable $e) {
            if (!$inTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($this->logger) {
                $this->logger->error('crypto_deposit.update_status_failed', [
                    'deposit_id' => $id,
                    'new_status' => $status,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            throw new \RuntimeException("Failed to update status for crypto deposit ID {$id}: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
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
                WHERE verification_status = 'pending'
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

    /**
     * Dynamic native search engine backed by unified Filterable architecture.
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset, string $sortDir = 'DESC'): array
    {
        $query = $this->db->table('crypto_deposits as d')
            ->select('d.*', 'u.full_name as user_name', 'u.email as user_email')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id');

        if (!empty($q)) {
            $query = $this->applySearch($query, $q);
        }

        $query = $this->applyFilters($query, $filters);

        $dir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('d.created_at', $dir)->limit($limit)->offset($offset)->get() ?? []
        ];
    }
}
