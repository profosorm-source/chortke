<?php

namespace App\Models;
use Core\Model;

use Core\Database;

class BugReport extends Model {
    protected static string $table = 'bug_reports';

    public ?int $id = null;
    public ?int $user_id = null;
    public ?string $page_url = null;
    public ?string $page_title = null;
    public ?string $category = null;
    public ?string $priority = null;
    public ?string $description = null;
    public ?string $screenshot_path = null;
    public ?string $status = null;
    public ?string $admin_note = null;
    public ?int $assigned_to = null;
    public ?int $resolved_by = null;
    public ?string $resolved_at = null;
    public ?string $ip_address = null;
    public ?string $user_agent = null;
    public ?string $device_fingerprint = null;
    public ?string $browser = null;
    public ?string $os = null;
    public ?string $screen_resolution = null;
    public ?int $daily_report_count = 1;
    public ?bool $is_suspicious = false;
    public ?string $created_at = null;
    public ?string $updated_at = null;
    public ?string $deleted_at = null;

    // JOIN fields
    public ?string $user_full_name = null;
    public ?string $user_email = null;
    public ?string $assigned_name = null;
    public ?string $resolved_name = null;
    public ?int $comment_count = null;

    /**
     * پیدا کردن با ID
     */
    public function find(int $id): ?self
    {
        $row = $this->db->fetch(
            "SELECT br.*, 
                    u.full_name as user_full_name, u.email as user_email,
                    r.full_name as resolved_name,
                    (SELECT COUNT(*) FROM bug_report_comments WHERE bug_report_id = br.id) as comment_count
             FROM " . static::$table . " br
             LEFT JOIN users u ON br.user_id = u.id
             LEFT JOIN users r ON br.resolved_by = r.id
             WHERE br.id = :id",
            ['id' => $id]
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function allFiltered(array $filters = [], int $limit = 20, int $offset = 0): array
    {
                $where = ["1=1"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "br.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[] = "br.priority = :priority";
            $params['priority'] = $filters['priority'];
        }

        if (!empty($filters['category'])) {
            $where[] = "br.category = :category";
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = "br.user_id = :user_id";
            $params['user_id'] = (int)$filters['user_id'];
        }

        if (isset($filters['is_suspicious']) && $filters['is_suspicious'] !== '') {
            $where[] = "br.is_suspicious = :is_suspicious";
            $params['is_suspicious'] = (int)$filters['is_suspicious'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(br.description LIKE :search OR br.page_url LIKE :search2 OR u.full_name LIKE :search3)";
            $params['search'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
            $params['search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = "br.created_at >= :date_from";
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = "br.created_at <= :date_to";
            $params['date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = \implode(' AND ', $where);

        $sql = "SELECT br.*, 
                       u.full_name as user_full_name, u.email as user_email,
                       (SELECT COUNT(*) FROM bug_report_comments WHERE bug_report_id = br.id) as comment_count
                FROM " . static::$table . " br
                LEFT JOIN users u ON br.user_id = u.id
                WHERE {$whereStr}
                ORDER BY 
                    CASE br.priority 
                        WHEN 'critical' THEN 1 
                        WHEN 'high' THEN 2 
                        WHEN 'normal' THEN 3 
                        WHEN 'low' THEN 4 
                    END ASC,
                    br.created_at DESC
                LIMIT :lmt OFFSET :ofst";

        $params['lmt'] = $limit;
        $params['ofst'] = $offset;

        $rows = $this->db->fetchAll($sql, $params);
        return \array_map([$this, 'hydrate'], $rows);
    }

    /**
     * شمارش
     */
    public function count(array $filters = []): int
    {
                $where = ["1=1"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "br.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['priority'])) {
            $where[] = "br.priority = :priority";
            $params['priority'] = $filters['priority'];
        }

        if (!empty($filters['category'])) {
            $where[] = "br.category = :category";
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = "br.user_id = :user_id";
            $params['user_id'] = (int)$filters['user_id'];
        }

        if (isset($filters['is_suspicious']) && $filters['is_suspicious'] !== '') {
            $where[] = "br.is_suspicious = :is_suspicious";
            $params['is_suspicious'] = (int)$filters['is_suspicious'];
        }

        if (!empty($filters['search'])) {
            $where[] = "(br.description LIKE :search OR br.page_url LIKE :search2)";
            $params['search'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $whereStr = \implode(' AND ', $where);

        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " br 
             LEFT JOIN users u ON br.user_id = u.id
             WHERE {$whereStr}",
            $params
        );
    }

    /**
     * گزارش‌های کاربر خاص
     */
    public function getByUser(int $userId, int $limit = 20, int $offset = 0): array
    {
                $rows = $this->db->fetchAll(
            "SELECT br.*,
                    (SELECT COUNT(*) FROM bug_report_comments WHERE bug_report_id = br.id AND is_internal = 0) as comment_count
             FROM " . static::$table . " br
             WHERE br.user_id = :uid
             ORDER BY br.created_at DESC
             LIMIT :lmt OFFSET :ofst",
            ['uid' => $userId, 'lmt' => $limit, 'ofst' => $offset]
        );
        return \array_map([$this, 'hydrate'], $rows);
    }

    /**
     * شمارش گزارش‌های امروز کاربر
     */
    public function countTodayByUser(int $userId): int
    {
                $today = \date('Y-m-d');
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " 
             WHERE user_id = :uid AND DATE(created_at) = :today",
            ['uid' => $userId, 'today' => $today]
        );
    }

    /**
     * بررسی گزارش مداوم روزانه (ضد تقلب)
     */
    public function countConsecutiveDays(int $userId, int $days = 5): int
    {
                $count = 0;

        for ($i = 0; $i < $days; $i++) {
            $date = \date('Y-m-d', \strtotime("-{$i} days"));
            $hasReport = (int)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM " . static::$table . " 
                 WHERE user_id = :uid AND DATE(created_at) = :d",
                ['uid' => $userId, 'd' => $date]
            );

            if ($hasReport > 0) {
                $count++;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * ایجاد گزارش
     */
    public function create(array $data): ?int
    {
        if (isset($data['page_title']) && !isset($data['title'])) {
            $data['title'] = $data['page_title'];
        }
        if (isset($data['screenshot_path']) && !isset($data['attachment'])) {
            $data['attachment'] = $data['screenshot_path'];
        }

        $fields = [
            'user_id', 'page_url', 'title', 'category', 'priority',
            'description', 'attachment', 'status', 'ip_address',
            'user_agent', 'is_suspicious'
        ];

        $insertData = [];
        foreach ($fields as $field) {
            if (\array_key_exists($field, $data)) {
                $insertData[$field] = $data[$field];
            }
        }
        $insertData['created_at'] = \date('Y-m-d H:i:s');

        if (!isset($insertData['status'])) {
            $insertData['status'] = 'open';
        }

        $columns = \implode(', ', \array_keys($insertData));
        $placeholders = ':' . \implode(', :', \array_keys($insertData));

        $this->db->query(
            "INSERT INTO " . static::$table . " ({$columns}) VALUES ({$placeholders})",
            $insertData
        );

        return (int)$this->db->lastInsertId() ?: null;
    }

    /**
     * بروزرسانی
     */
    public function update(int $id, array $data): bool
    {
                $allowed = [
            'status', 'priority', 'category',
            'resolved_by', 'resolved_at', 'is_suspicious'
        ];

        $sets = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $sets[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sets[] = "updated_at = :updated_at";
        $params['updated_at'] = \date('Y-m-d H:i:s');

        $setStr = \implode(', ', $sets);
        $stmt = $this->db->query(
            "UPDATE " . static::$table . " SET {$setStr} WHERE id = :id",
            $params
        );
        return $stmt ? true : false;
    }

    /**
     * حذف نرم
     */
    public function softDelete(int $id): bool
    {
                return $this->db->query(
            "UPDATE " . static::$table . " SET status = 'closed' WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * آمار گزارش‌ها
     */
    public function getStats(): array
    {
                $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table
        );

        $open = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE status = 'open'"
        );

        $inProgress = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE status = 'in_progress'"
        );

        $resolved = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE status = 'resolved'"
        );

        $critical = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE priority = 'critical' AND status IN ('open','in_progress')"
        );

        $suspicious = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE is_suspicious = 1"
        );

        $todayCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE DATE(created_at) = :today",
            ['today' => \date('Y-m-d')]
        );

        // میانگین زمان حل (ساعت)
        $avgResolveTime = $this->db->fetchColumn(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) 
             FROM " . static::$table . " 
             WHERE resolved_at IS NOT NULL"
        );

        return [
            'total' => $total,
            'open' => $open,
            'in_progress' => $inProgress,
            'resolved' => $resolved,
            'critical' => $critical,
            'suspicious' => $suspicious,
            'today' => $todayCount,
            'avg_resolve_hours' => $avgResolveTime ? \round((float)$avgResolveTime, 1) : 0,
        ];
    }

    /**
     * آمار بر اساس دسته‌بندی
     */
    public function getStatsByCategory(): array
    {
                return $this->db->fetchAll(
            "SELECT category, COUNT(*) as count 
             FROM " . static::$table . " 
             GROUP BY category 
             ORDER BY count DESC"
        );
    }

    /**
     * آمار روزانه
     */
    public function getDailyStats(int $days = 30): array
    {
                return $this->db->fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM " . static::$table . " 
             WHERE created_at > DATE_SUB(NOW(), INTERVAL :days DAY) 
             GROUP BY DATE(created_at) 
             ORDER BY date ASC",
            ['days' => $days]
        );
    }

    protected function hydrate($row): self
    {
        $obj = new self($this->db);
        if (\is_array($row)) {
            $row = (object)$row;
        }

        $obj->id = isset($row->id) ? (int)$row->id : null;
        $obj->user_id = isset($row->user_id) ? (int)$row->user_id : null;
        $obj->page_url = $row->page_url ?? null;
        $obj->page_title = $row->page_title ?? null;
        $obj->category = $row->category ?? null;
        $obj->priority = $row->priority ?? null;
        $obj->description = $row->description ?? null;
        $obj->screenshot_path = $row->screenshot_path ?? null;
        $obj->status = $row->status ?? null;
        $obj->admin_note = $row->admin_note ?? null;
        $obj->assigned_to = isset($row->assigned_to) ? (int)$row->assigned_to : null;
        $obj->resolved_by = isset($row->resolved_by) ? (int)$row->resolved_by : null;
        $obj->resolved_at = $row->resolved_at ?? null;
        $obj->ip_address = $row->ip_address ?? null;
        $obj->user_agent = $row->user_agent ?? null;
        $obj->device_fingerprint = $row->device_fingerprint ?? null;
        $obj->browser = $row->browser ?? null;
        $obj->os = $row->os ?? null;
        $obj->screen_resolution = $row->screen_resolution ?? null;
        $obj->daily_report_count = isset($row->daily_report_count) ? (int)$row->daily_report_count : 1;
        $obj->is_suspicious = isset($row->is_suspicious) ? (bool)$row->is_suspicious : false;
        $obj->created_at = $row->created_at ?? null;
        $obj->updated_at = $row->updated_at ?? null;
        $obj->deleted_at = $row->deleted_at ?? null;

        // JOIN fields
        $obj->user_full_name = $row->user_full_name ?? null;
        $obj->user_email = $row->user_email ?? null;
        $obj->assigned_name = $row->assigned_name ?? null;
        $obj->resolved_name = $row->resolved_name ?? null;
        $obj->comment_count = isset($row->comment_count) ? (int)$row->comment_count : null;

        return $obj;
    }
}