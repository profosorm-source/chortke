<?php

namespace App\Models;

use Core\Model;

class LedgerEntry extends Model
{
    protected static string $table = 'ledger_entries';

    public function create(array $data): ?object
    {
        $transactionId = trim((string)($data['transaction_id'] ?? ''));
        if ($transactionId === '') {
            throw new \InvalidArgumentException('LedgerEntry requires a valid transaction_id');
        }
        $data['transaction_id'] = $transactionId;

        $data['account'] = $data['account'] ?? 'unknown';
        $debitVal = (string)($data['debit'] ?? '0');
        $creditVal = (string)($data['credit'] ?? '0');

        if (\Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($debitVal))) || \Core\ValueObjects\Money::fromString((string)('0'))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($creditVal)))) {
            throw new \InvalidArgumentException('debit and credit must be non-negative values');
        }

        $hasDebit = \Core\ValueObjects\Money::fromString((string)($debitVal))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)('0')));
        $hasCredit = \Core\ValueObjects\Money::fromString((string)($creditVal))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)('0')));

        if (($hasDebit && $hasCredit) || (!$hasDebit && !$hasCredit)) {
            throw new \InvalidArgumentException('LedgerEntry must have either debit or credit, but not both or neither');
        }

        // Unique constraint check to prevent duplicate posting of the same leg
        $stmt = $this->db->prepare(
            "SELECT id FROM ledger_entries 
             WHERE transaction_id = ? AND account = ? AND currency = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([
            $data['transaction_id'],
            $data['account'],
            $data['currency'] ?? 'irt'
        ]);
        if ($stmt->fetch()) {
            throw new \RuntimeException('Duplicate ledger entry leg detected for transaction ' . $data['transaction_id']);
        }

        $data['debit'] = $debitVal;
        $data['credit'] = $creditVal;
        $data['currency'] = $data['currency'] ?? 'irt';
        $data['description'] = $data['description'] ?? null;
        $data['metadata'] = isset($data['metadata']) && is_array($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : ($data['metadata'] ?? null);
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');
        $data['updated_at'] = $data['updated_at'] ?? date('Y-m-d H:i:s');

        try {
            $id = parent::create($data);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || strpos($e->getMessage(), '1062') !== false) {
                throw new \RuntimeException('Duplicate ledger entry leg detected for transaction ' . $data['transaction_id'], 0, $e);
            }
            throw $e;
        }
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
