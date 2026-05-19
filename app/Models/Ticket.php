<?php

namespace App\Models;

use Core\Model;
use Core\Database;
use App\Traits\Filterable;

class Ticket extends Model {
    use Filterable;

    protected static string $table = 'tickets';
    protected static array $searchable = ['t.subject', 't.ticket_id'];

    protected static array $filterable = [
        'status' => ['t.status', '='],
        'priority' => ['t.priority', '='],
        'category_id' => ['t.category_id', '='],
        'assigned_to' => ['t.assigned_to', '='],
        'user_id' => ['t.user_id', '='],
    ];

/* -------------------------
     * Helpers (DB fetch wrappers)
     * ------------------------- */
    private function fetchOne(string $sql, array $params = []): ?object
    {
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return null;

        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    private function fetchAllRows(string $sql, array $params = []): array
    {
        $stmt = $this->db->query($sql, $params);
        if (!$stmt) return [];

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    private function execBool(string $sql, array $params = []): bool
    {
        $stmt = $this->db->query($sql, $params);
        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }
        return (bool)$stmt;
    }

    /**
     * ایجاد تیکت جدید
     */
    public function create(array $data): ?int
    {
        $sql = "INSERT INTO tickets
                (user_id, category_id, subject, priority, status, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'open', ?, NOW(), NOW())";

        $ok = $this->db->query($sql, [
            $data['user_id'],
            $data['category_id'],
            $data['subject'],
            $data['priority'] ?? 'normal',
            $data['metadata'] ?? null,
        ]);

        if (!$ok) {
            return null;
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * دریافت با ID
     */
    public function findById(int $id): ?object
    {
        $sql = "SELECT t.*, tc.name as category_name,
                       u.full_name as user_name, u.email as user_email
                FROM tickets t
                JOIN ticket_categories tc ON t.category_id = tc.id
                JOIN users u ON t.user_id = u.id
                WHERE t.id = ?";

        return $this->fetchOne($sql, [$id]);
    }

    /**
     * دریافت تیکت‌های کاربر
     */
    public function getUserTickets(int $userId, ?string $status = null, int $page = 1, int $perPage = 20): array
    {
        $page = \max(1, (int)$page);
        $perPage = \max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT t.*, tc.name as category_name
                FROM tickets t
                JOIN ticket_categories tc ON t.category_id = tc.id
                WHERE t.user_id = ?";

        $params = [$userId];

        if ($status) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY t.updated_at DESC LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش تیکت‌های کاربر
     */
    public function countUserTickets(int $userId, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM tickets WHERE user_id = ?";
        $params = [$userId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $row = $this->fetchOne($sql, $params);
        return $row ? (int)$row->count : 0;
    }

    /**
     * دریافت تیکت‌ها برای ادمین
     */
    public function getForAdmin(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page = \max(1, (int)$page);
        $perPage = \max(1, (int)$perPage);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT t.*, tc.name as category_name, u.full_name, u.email
                FROM tickets t
                JOIN ticket_categories tc ON t.category_id = tc.id
                JOIN users u ON t.user_id = u.id
                WHERE 1=1";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND t.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND t.priority = ?";
            $params[] = $filters['priority'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND t.category_id = ?";
            $params[] = (int)$filters['category_id'];
        }

        if (!empty($filters['assigned_to'])) {
            $sql .= " AND t.assigned_to = ?";
            $params[] = (int)$filters['assigned_to'];
        }

        $sql .= " ORDER BY
                    CASE t.priority
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'normal' THEN 3
                        WHEN 'low' THEN 4
                        ELSE 5
                    END,
                    t.updated_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $index = 1;
        foreach ($params as $val) {
            $stmt->bindValue($index++, $val);
        }
        $stmt->bindValue($index++, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue($index++, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش برای ادمین
     */
    public function countForAdmin(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM tickets WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $sql .= " AND priority = ?";
            $params[] = $filters['priority'];
        }

        if (!empty($filters['category_id'])) {
            $sql .= " AND category_id = ?";
            $params[] = (int)$filters['category_id'];
        }

        $row = $this->fetchOne($sql, $params);
        return $row ? (int)$row->count : 0;
    }

    /**
     * بروزرسانی وضعیت
     */
    public function updateStatus(int $id, string $status): bool
    {
        $data = ['status' => $status];

        if ($status === 'closed') {
            $data['closed_at'] = \date('Y-m-d H:i:s');
        }

        return $this->update($id, $data);
    }

    /**
     * بروزرسانی آخرین پاسخ
     */
    public function updateLastReply(int $id, string $replyBy): bool
    {
        $sql = "UPDATE tickets
                SET status = CASE
                        WHEN status = 'closed' THEN 'open'
                        WHEN ? = 'admin' THEN 'answered'
                        ELSE status
                    END,
                    updated_at = NOW()
                WHERE id = ?";

        return $this->execBool($sql, [$replyBy, $id]);
    }

    /**
     * تخصیص به ادمین
     */
    public function assign(int $id, int $adminId): bool
    {
        $sql = "UPDATE tickets SET assigned_to = ?, updated_at = NOW() WHERE id = ?";
        return $this->execBool($sql, [$adminId, $id]);
    }

    /**
     * بروزرسانی
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) return false;

        $allowedColumns = ['category_id', 'subject', 'priority', 'status', 'metadata', 'assigned_to', 'closed_at'];
        $fields = [];
        $params = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowedColumns, true)) {
                continue;
            }
            $fields[] = "{$key} = ?";
            $params[] = $value;
        }

        if (empty($fields)) return false;

        // همیشه updated_at را بروز کن
        $fields[] = "updated_at = NOW()";

        $params[] = $id;

        $sql = "UPDATE tickets SET " . \implode(', ', $fields) . " WHERE id = ?";

        return $this->execBool($sql, $params);
    }

    /**
     * آمار تیکت‌ها
     */
    public function getStats(): array
    {
        $sql = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open,
                    SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'on_hold' THEN 1 ELSE 0 END) as on_hold,
                    SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as closed,
                    SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent
                FROM tickets";

        $row = $this->fetchOne($sql);

        return [
            'total' => $row ? (int)$row->total : 0,
            'open' => $row ? (int)$row->open : 0,
            'answered' => $row ? (int)$row->answered : 0,
            'in_progress' => $row ? (int)$row->in_progress : 0,
            'on_hold' => $row ? (int)$row->on_hold : 0,
            'closed' => $row ? (int)$row->closed : 0,
            'urgent' => $row ? (int)$row->urgent : 0,
        ];
    }

    /**
     * Advanced search logic utilizing Central Filterable architecture.
     */
    public function searchNative(string $q, array $filters, int $limit, int $offset): array
    {
        $query = $this->db->table('tickets as t')
            ->select('t.*', 'tc.name as category_name', 'u.full_name as user_name', 'u.email as user_email')
            ->leftJoin('ticket_categories as tc', 'tc.id', '=', 't.category_id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id');

        if (!empty($q)) {
            $query = $this->applySearch($query, $q);
        }

        $query = $this->applyFilters($query, $filters);

        // Specialized intelligent support prioritization
        $query->orderByRaw("CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
              ->orderBy('t.updated_at', 'DESC');

        return [
            'total' => $query->count(),
            'items' => (clone $query)->limit($limit)->offset($offset)->get() ?? []
        ];
    }
}