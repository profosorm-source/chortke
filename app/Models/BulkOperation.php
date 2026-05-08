<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class BulkOperation extends Model
{
    protected static string $table = 'bulk_operations';

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

    public function applyBatchUpdate(string $table, array $ids, array $data, string $idColumn = 'id'): int
    {
        if (empty($ids) || empty($data)) {
            return 0;
        }

        if (!isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        $sets = [];
        $params = [];

        foreach ($data as $column => $value) {
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
        if (empty($ids)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "DELETE FROM `{$table}` WHERE `{$idColumn}` IN ({$placeholders})";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    public function executeQuery(string $sql, array $params = []): array
    {
        $stmt = $this->db->query($sql, $params);
        if ($stmt instanceof \PDOStatement) {
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        return [];
    }

    public function executeBatch(string $sql, array $batchParams): int
    {
        $stmt = $this->db->prepare($sql);
        $affected = 0;

        foreach ($batchParams as $params) {
            $stmt->execute($params);
            $affected += $stmt->rowCount();
        }

        return $affected;
    }
}
