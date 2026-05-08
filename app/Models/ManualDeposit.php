<?php

namespace App\Models;

use Core\Model;

class ManualDeposit extends Model
{
    protected static string $table = 'manual_deposits';

    /**
     * ایجاد درخواست واریز دستی
     * ایجاد رکورد جدید
     */
    public function create(array $data): ?object
    {
        // timestamps (اگر در جدول دارید)
        $now = \date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        $idOrBool = parent::create($data); // int|true|false (بسته به insert)

        if (\is_int($idOrBool) && $idOrBool > 0) {
    return $this->find((int)$idOrBool);
}
        return null;
    }

    /**
     * دریافت درخواست‌های واریز کاربر
     */
    public function getUserDeposits(int $userId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT d.*, c.card_number, c.bank_name
                FROM " . static::$table . " d
                LEFT JOIN user_bank_cards c ON d.card_id = c.id
                WHERE d.user_id = :user_id";

        $params = ['user_id' => $userId];

        if ($status) {
            $sql .= " AND d.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY d.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * بررسی وجود درخواست در انتظار
     */
    public function hasPendingDeposit(int $userId): bool
    {
        $sql = "SELECT COUNT(*) as count
                FROM " . static::$table . "
                WHERE user_id = :user_id AND status IN ('pending', 'under_review')";

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
        ?int $reviewedBy = null,
        ?string $transactionId = null
    ): bool {
        $sql = "UPDATE " . static::$table . " SET status = :status, updated_at = NOW()";
        $params = ['id' => $id, 'status' => $status];

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

    /**
     * دریافت درخواست‌های در انتظار (برای ادمین)
     */
    public function getPendingDeposits(int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT d.*, u.full_name, u.email, c.card_number, c.bank_name
                FROM " . static::$table . " d
                LEFT JOIN users u ON d.user_id = u.id
                LEFT JOIN user_bank_cards c ON d.card_id = c.id
                WHERE d.status IN ('pending', 'under_review')
                ORDER BY d.created_at ASC
                LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش درخواست‌های در انتظار
     */
    public function countPendingDeposits(): int
    {
        $sql = "SELECT COUNT(*) as count
                FROM " . static::$table . "
                WHERE status IN ('pending', 'under_review')";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }

    /**
     * دریافت تمام درخواست‌ها (برای ادمین)
     */
    public function getAll(?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT d.*, u.full_name, u.email, c.card_number, c.bank_name
                FROM " . static::$table . " d
                LEFT JOIN users u ON d.user_id = u.id
                LEFT JOIN user_bank_cards c ON d.card_id = c.id
                WHERE 1=1";

        $params = [];

        if ($status) {
            $sql .= " AND d.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY d.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش کل درخواست‌ها
     */
    public function countAll(?string $status = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . static::$table . " WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }
}