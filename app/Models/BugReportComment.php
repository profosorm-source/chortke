<?php

namespace App\Models;
use Core\Model;

use Core\Database;

class BugReportComment extends Model {
    protected static string $table = 'bug_report_comments';

    public ?int $id = null;
    public ?int $bug_report_id = null;
    public ?int $user_id = null;
    public ?string $user_type = null;
    public ?string $comment = null;
    public ?string $attachment_path = null;
    public ?bool $is_internal = false;
    public ?string $created_at = null;

    // JOIN
    public ?string $user_full_name = null;

    /**
     * دریافت کامنت‌های یک گزارش
     */
    public function getByReport(int $reportId, bool $includeInternal = false): array
    {
                $internalCondition = $includeInternal ? '' : ' AND brc.is_internal = 0';

        $rows = $this->db->fetchAll(
            "SELECT brc.*, u.full_name as user_full_name
             FROM " . static::$table . " brc
             LEFT JOIN users u ON brc.user_id = u.id
             WHERE brc.bug_report_id = :rid{$internalCondition}
             ORDER BY brc.created_at ASC",
            ['rid' => $reportId]
        );

        return \array_map([$this, 'hydrate'], $rows);
    }

    /**
     * ایجاد کامنت
     */
    public function create(array $data): ?int
    {
                $this->db->query(
            "INSERT INTO " . static::$table . " 
             (bug_report_id, user_id, comment, attachment_path, is_internal, created_at) 
             VALUES (:brid, :uid, :comment, :attach, :internal, NOW())",
            [
                'brid' => $data['bug_report_id'],
                'uid' => $data['user_id'],
                'comment' => $data['comment'],
                'attach' => $data['attachment_path'] ?? null,
                'internal' => (int)($data['is_internal'] ?? 0),
            ]
        );

        return (int)$this->db->lastInsertId() ?: null;
    }

    protected function hydrate($row): self
    {
        $obj = new self($this->db);
        if (\is_array($row)) {
            $row = (object)$row;
        }

        $obj->id = isset($row->id) ? (int)$row->id : null;
        $obj->bug_report_id = isset($row->bug_report_id) ? (int)$row->bug_report_id : null;
        $obj->user_id = isset($row->user_id) ? (int)$row->user_id : null;
        $obj->comment = $row->comment ?? null;
        $obj->attachment_path = $row->attachment_path ?? null;
        $obj->is_internal = isset($row->is_internal) ? (bool)$row->is_internal : false;
        $obj->created_at = $row->created_at ?? null;
        $obj->user_full_name = $row->user_full_name ?? null;

        return $obj;
    }
}