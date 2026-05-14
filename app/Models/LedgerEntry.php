<?php

namespace App\Models;

use Core\Model;

class LedgerEntry extends Model
{
    protected static string $table = 'ledger_entries';

    public function create(array $data): ?object
    {
        $data['transaction_id'] = $data['transaction_id'] ?? '';
        $data['account'] = $data['account'] ?? 'unknown';
        $data['debit'] = $data['debit'] ?? 0;
        $data['credit'] = $data['credit'] ?? 0;
        $data['currency'] = $data['currency'] ?? 'irt';
        $data['description'] = $data['description'] ?? null;
        $data['metadata'] = isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : ($data['metadata'] ?? null);
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        $id = parent::create($data);
        return is_int($id) ? $this->find($id) : null;
    }

    public function getByTransactionId(string $transactionId): array
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE transaction_id = :transaction_id ORDER BY created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['transaction_id' => $transactionId]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }
}
