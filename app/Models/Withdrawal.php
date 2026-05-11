<?php

namespace App\Models;

use Core\Model;

class Withdrawal extends Model
{
    protected static string $table = 'withdrawals';
    protected static array $searchable = ['withdrawals.tracking_code'];

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

    /**
     * بررسی وجود درخواست در انتظار
     */
    public function hasPendingWithdrawal(int $userId): bool
    {
        $sql = "SELECT COUNT(*) as count
                FROM " . static::$table . "
                WHERE user_id = :user_id AND status IN ('pending', 'processing')";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return ((int)($result->count ?? 0)) > 0;
    }

    /**
     * بروزرسانی وضعیت
     */
    public function updateStatus(
        int $id,
        string $status,
        ?string $rejectionReason = null,
        ?int $processedBy = null,
        ?string $transactionId = null
    ): bool {
        try {
            $this->db->beginTransaction();
            
            // 1. دریافت withdrawal
            $withdrawal = $this->find($id);
            if (!$withdrawal) {
                $this->db->rollback();
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
                $this->db->rollback();
                return false;
            }
            
            // 3. اگر completed شد → پردازش مالی کسر موجودی قفل‌شده
            if ($status === 'completed') {
                $walletModel = new Wallet($this->db);
                
                // بررسی موجودی قفل‌شده کافی
                $lockedBalance = $walletModel->getLockedBalance((int)$withdrawal->user_id, (string)$withdrawal->currency);
                if ($lockedBalance < (float)$withdrawal->amount) {
                    $this->db->rollback();
                    throw new \Exception('Insufficient locked balance to complete withdrawal.');
                }
                
                // کسر از قفل‌شده
                $walletModel->deductLocked((int)$withdrawal->user_id, (float)$withdrawal->amount, (string)$withdrawal->currency);
                
                // بروزرسانی زمان آخرین برداشت
                $walletModel->updateLastWithdrawal((int)$withdrawal->user_id);
                
                // ثبت transaction log
                $transactionModel = new Transaction($this->db, \Core\Container::getInstance()->make(\App\Contracts\LoggerInterface::class));
                $transactionModel->create([
                    'user_id' => $withdrawal->user_id,
                    'type' => 'withdraw',
                    'amount' => $withdrawal->amount,
                    'currency' => $withdrawal->currency,
                    'status' => 'completed',
                    'reference_id' => "withdrawal_{$id}",
                    'metadata' => ['withdrawal_id' => $id]
                ]);
            }
            
            // 4. اگر rejected شد → بازگرداندن و آزاد کردن موجودی قفل‌شده
            if ($status === 'rejected') {
                $walletModel = new Wallet($this->db);
                $walletModel->unlockBalance((int)$withdrawal->user_id, (float)$withdrawal->amount, (string)$withdrawal->currency);
            }
            
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
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollback();
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
}