<?php

namespace App\Models;

use Core\Model;
use App\Traits\Filterable;

class ManualDeposit extends Model
{
    use Filterable;

    protected static string $table = 'manual_deposits';
    protected static array $searchable = ['d.tracking_code'];

    protected static array $filterable = [
        'status' => ['d.status', '='],
        'user_id' => ['d.user_id', '='],
    ];

    /**
     * ایجاد درخواست واریز دستی
     * ایجاد رکورد جدید
     */
    public function create(array $data): ?object
    {
        $now = \date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        $idOrBool = parent::create($data);

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
                LEFT JOIN bank_cards c ON d.card_id = c.id
                WHERE d.user_id = :user_id";

        $params = ['user_id' => $userId];

        if ($status) {
            $sql .= " AND d.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * بررسی وجود درخواست در انتظار
     */
    public function hasPendingDeposit(int $userId, bool $forUpdate = false): bool
    {
        $sql = "SELECT id
                FROM " . static::$table . "
                WHERE user_id = :user_id AND status IN ('pending', 'under_review')";
        if ($forUpdate && $this->db->inTransaction()) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        return (bool)$stmt->fetch();
    }

    /**
     * بروزرسانی وضعیت
     */
    public function updateStatus(
        int $id,
        string $status,
        ?string $rejectionReason = null,
        ?int $reviewedBy = null,
        ?string $transactionId = null,
        ?string $note = null,
        array $allowedFromStatuses = ['pending', 'under_review']
    ): bool {
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('ManualDeposit::updateStatus() requires an active database transaction.');
        }
        $sql = "UPDATE " . static::$table . " SET status = :status, updated_at = NOW()";
        $params = ['id' => $id, 'status' => $status];

        if ($rejectionReason !== null) {
            $sql .= ", rejection_reason = :rejection_reason";
            $params['rejection_reason'] = $rejectionReason;
        }

        if ($reviewedBy !== null) {
            $sql .= ", reviewed_by = :reviewed_by, reviewed_at = NOW()";
            $params['reviewed_by'] = $reviewedBy;
        }

        if ($transactionId !== null) {
            $sql .= ", transaction_id = :transaction_id";
            $params['transaction_id'] = $transactionId;
        }

        if ($note !== null) {
            $sql .= ", admin_note = :admin_note";
            $params['admin_note'] = $note;
        }

        $placeholders = [];
        foreach ($allowedFromStatuses as $index => $fromStatus) {
            $key = "from_status_" . $index;
            $placeholders[] = ":" . $key;
            $params[$key] = $fromStatus;
        }

        $sql .= " WHERE id = :id AND status IN (" . implode(", ", $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException("Concurrent modification detected: status is not in allowed source states or row does not exist.");
        }
        return true;
    }

    /**
     * دریافت درخواست برای استفاده در transaction (with FOR UPDATE lock)
     */
    public function findForUpdate(int $id): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE id = ? FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_OBJ) ?: null;
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
                LEFT JOIN bank_cards c ON d.card_id = c.id
                WHERE d.status IN ('pending', 'under_review')
                ORDER BY d.created_at ASC
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
                LEFT JOIN bank_cards c ON d.card_id = c.id
                WHERE 1=1";

        $params = [];

        if ($status) {
            $sql .= " AND d.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
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

    /**
     * Advanced Dynamic Filter Engine backed by Central Filterable system.
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset, string $sortDir = 'DESC'): array
    {
        $query = $this->db->table('manual_deposits as d')
            ->select('d.*', 'u.full_name as user_name', 'u.email as user_email', 'c.card_number', 'c.bank_name')
            ->leftJoin('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('bank_cards as c', 'c.id', '=', 'd.card_id');

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