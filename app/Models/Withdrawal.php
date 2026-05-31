<?php

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

class Withdrawal extends Model
{
    use Filterable;

    protected static string $table = 'withdrawals';
    protected static array $searchable = ['w.tracking_code'];

    protected static array $filterable = [
        'status' => ['w.status', '='],
        'currency' => ['w.currency', '='],
        'user_id' => ['w.user_id', '='],
    ];

    /**
     * ایجاد درخواست برداشت
     * ایجاد رکورد جدید
     */
    public function create(array $data): ?object
    {
        $now = \date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        $idOrBool = parent::create($data); // int|true|...

        if (\is_int($idOrBool) && $idOrBool > 0) {
            return $this->find((int)$idOrBool);
        }
        return null;
    }

    /**
     * قفل کردن رکورد برداشت برای جلوگیری از تداخل تراکنش‌ها (Pessimistic Locking)
     */
    public function lockForUpdate(int $id): ?object
    {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Withdrawal::lockForUpdate() requires an active transaction.');
        }

        $stmt = $this->db->prepare("SELECT * FROM " . static::$table . " WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        
        return $result ?: null;
    }

    public function getSummaryStats(): array
    {
        $sql = "SELECT
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected,
            COALESCE(SUM(CASE WHEN status='completed' THEN amount ELSE 0 END),0) AS total_amount
        FROM " . static::$table;
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: ['pending'=>0,'completed'=>0,'rejected'=>0,'total_amount'=>0];
    }

    /**
     * دریافت درخواست‌های برداشت کاربر
     */
    public function getUserWithdrawals(
        int $userId,
        ?string $status = null,
        ?string $currency = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT w.*, c.card_number, c.bank_name
                FROM " . static::$table . " w
                LEFT JOIN bank_cards c ON w.card_id = c.id
                WHERE w.user_id = :user_id";

        $params = ['user_id' => $userId];

        if ($status) {
            $sql .= " AND w.status = :status";
            $params['status'] = $status;
        }

        if ($currency) {
            $sql .= " AND w.currency = :currency";
            $params['currency'] = $currency;
        }

        $sql .= " ORDER BY w.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . \ltrim($key, ':'), $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function hasPendingWithdrawal(int $userId, bool $forUpdate = false): bool
    {
        $sql = "SELECT id
                FROM " . static::$table . "
                WHERE user_id = :user_id AND status IN ('pending', 'processing') LIMIT 1";
        if ($forUpdate && $this->db->inTransaction()) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return !empty($result);
    }

    public function hasPending(int $userId, bool $forUpdate = false): bool
    {
        return $this->hasPendingWithdrawal($userId, $forUpdate);
    }

    /**
     * بروزرسانی وضعیت
     * M43: Financial logic removed - TransactionService responsibility
     */
    public function updateStatus(
        int $id,
        string $status,
        ?string $rejectionReason = null,
        ?int $processedBy = null,
        ?string $transactionId = null
    ): bool {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('Withdrawal::updateStatus() requires an active transaction.');
        }

        try {
            // H14 Fix (BUG-05): دریافت رکورد درخواست برداشت با قفل بدبینانه ردیفی جهت جلوگیری از تداخل ادمین‌ها (BUG-12)
            $withdrawal = $this->lockForUpdate($id);
            if (!$withdrawal) {
                return false;
            }
            
            // 2. Validate state transition
            $validTransitions = [
                'pending' => ['processing', 'rejected'],
                'processing' => ['completed', 'rejected'],
                'completed' => [], // state terminal
                'rejected' => [], // state terminal
            ];
            
            $currentStatus = (string)($withdrawal->status ?? '');
            if (!isset($validTransitions[$currentStatus]) || 
                !\in_array($status, $validTransitions[$currentStatus], true)) {
                return false;
            }
            
            // M43: Service layer (WithdrawalAdminService / WithdrawalUserService) handles:
            // - Locked balance deduction on 'completed'
            // - Balance unlock on 'rejected'
            // - Transaction log creation
            // - Last withdrawal timestamp update
            // Model only updates withdrawal record
            
            // 5. Update withdrawal record
            $sql = "UPDATE " . static::$table . " SET status = :status, updated_at = NOW()";
            $params = ['id' => $id, 'status' => $status];
            
            if ($rejectionReason !== null) {
                $sql .= ", rejection_reason = :rejection_reason";
                $params['rejection_reason'] = $rejectionReason;
            }
            
            if ($processedBy !== null) {
                $sql .= ", processed_by = :processed_by, processed_at = NOW()";
                $params['processed_by'] = $processedBy;
            }
            
            if ($transactionId !== null) {
                $sql .= ", transaction_id = :transaction_id";
                $params['transaction_id'] = $transactionId;
            }
            
            $sql .= " WHERE id = :id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return true;
            
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * دریافت درخواست‌های در انتظار (برای ادمین)
     */
    public function getPendingWithdrawals(int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT w.*, u.full_name, u.email, c.card_number, c.bank_name, c.sheba
                FROM " . static::$table . " w
                LEFT JOIN users u ON w.user_id = u.id
                LEFT JOIN bank_cards c ON w.card_id = c.id
                WHERE w.status = 'pending'
                ORDER BY w.created_at ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش درخواست‌های در انتظار
     */
    public function countPendingWithdrawals(): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . static::$table . " WHERE status = 'pending'";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }

    /**
     * دریافت تمام درخواست‌ها (برای ادمین)
     */
    public function getAll(?string $status = null, ?string $currency = null, int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT w.*, u.full_name, u.email, c.card_number, c.bank_name
                FROM " . static::$table . " w
                LEFT JOIN users u ON w.user_id = u.id
                LEFT JOIN bank_cards c ON w.card_id = c.id
                WHERE 1=1";

        $params = [];

        if ($status) {
            $sql .= " AND w.status = :status";
            $params['status'] = $status;
        }

        if ($currency) {
            $sql .= " AND w.currency = :currency";
            $params['currency'] = $currency;
        }

        $sql .= " ORDER BY w.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . \ltrim($key, ':'), $val);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش کل درخواست‌ها
     */
    public function countAll(?string $status = null, ?string $currency = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . static::$table . " WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        if ($currency) {
            $sql .= " AND currency = :currency";
            $params['currency'] = $currency;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }

    /**
     * Advanced dynamic filter engine backed by central Filterable architecture.
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset, string $sortDir = 'DESC'): array
    {
        $query = $this->db->table('withdrawals as w')
            ->select('w.*', 'u.full_name as user_name', 'u.email as user_email', 'c.card_number', 'c.bank_name')
            ->leftJoin('users as u', 'u.id', '=', 'w.user_id')
            ->leftJoin('bank_cards as c', 'c.id', '=', 'w.card_id');

        if (!empty($q)) {
            $query = $this->applySearch($query, $q);
        }

        $query = $this->applyFilters($query, $filters);

        $dir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        return [
            'total' => $query->count(),
            'items' => (clone $query)->orderBy('w.created_at', $dir)->limit($limit)->offset($offset)->get() ?? []
        ];
    }
}