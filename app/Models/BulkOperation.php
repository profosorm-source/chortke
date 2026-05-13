<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class BulkOperation extends Model
{
    protected static string $table = 'bulk_operations';

    private const ALLOWED_TABLES = [
        'users', 'transactions', 'submissions', 'content_submissions',
        'custom_tasks', 'bug_reports', 'bulk_operations', 'content_revenues', 'banners'
    ];

    private const ALLOWED_COLUMNS = [
        'id', 'user_id', 'task_id', 'submission_id', 'content_id'
    ];

    private function validateTableName(string $table): void
    {
        if (!\in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException("Invalid table name: " . $table);
        }
    }

    private function validateColumnName(string $column): void
    {
        if (!\in_array($column, self::ALLOWED_COLUMNS, true)) {
            throw new \InvalidArgumentException("Invalid column name: " . $column);
        }
    }

    public function queueOperation(array $payload): int
    {
        return (int)$this->db->table(self::$table)
            ->insert($payload);
    }

    public function getPendingOperations(int $limit = 100): array
    {
        return $this->db->table(self::$table)
            ->select('*')
            ->where('processed', '=', 0)
            ->orderBy('created_at', 'ASC')
            ->limit($limit)
            ->get() ?? [];
    }

    public function markProcessed(int $operationId): bool
    {
        return (bool)$this->db->table(self::$table)
            ->where('id', '=', $operationId)
            ->update(['processed' => 1, 'processed_at' => date('Y-m-d H:i:s')]);
    }

    private const ALLOWED_UPDATE_COLUMNS = [
        'status', 'kyc_status', 'tier_level', 'priority', 'processed', 'processed_at', 'updated_at', 'admin_note', 'resolved_at'
    ];

    public function applyBatchUpdate(string $table, array $ids, array $data, string $idColumn = 'id'): int
    {
        $this->validateTableName($table);
        $this->validateColumnName($idColumn);

        if (empty($ids) || empty($data)) {
            return 0;
        }

        // Limit maximum IDs in a single batch
        $ids = \array_slice($ids, 0, 1000);

        if (!isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $sets = [];
        $params = [];

        foreach ($data as $column => $value) {
            if (!\in_array($column, self::ALLOWED_UPDATE_COLUMNS, true)) {
                throw new \InvalidArgumentException("Invalid or restricted update column: " . $column);
            }
            $sets[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($params, $ids);

        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$idColumn}` IN ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function applyBatchDelete(string $table, array $ids, string $idColumn = 'id'): int
    {
        $this->validateTableName($table);
        $this->validateColumnName($idColumn);

        if (empty($ids)) {
            return 0;
        }

        // Limit maximum IDs in a single batch
        $ids = \array_slice($ids, 0, 1000);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM `{$table}` WHERE `{$idColumn}` IN ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        return $stmt->rowCount();
    }
}
