<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LedgerEntry;
use Core\Database;
use App\Contracts\LoggerInterface;

class LedgerService extends \App\Services\BaseService
{
    private LedgerEntry $ledgerEntry;
    private Database $db;

    public function __construct(LedgerEntry $ledgerEntry, Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->ledgerEntry = $ledgerEntry;
        $this->db = $db;
    }

    public function recordEntry(array $data): ?object
    {
        return $this->ledgerEntry->create($data);
    }

    public function recordDoubleEntry(
        string $transactionId,
        string $debitAccount,
        string $creditAccount,
        float $amount,
        string $description = null,
        array $metadata = []
    ): bool {
        if ($amount <= 0) {
            return false;
        }

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }
            $common = [
                'transaction_id' => $transactionId,
                'description' => $description,
                'metadata' => $metadata,
            ];

            $debit = $this->recordEntry(array_merge($common, [
                'account' => $debitAccount,
                'debit' => $amount,
                'credit' => 0,
            ]));

            $credit = $this->recordEntry(array_merge($common, [
                'account' => $creditAccount,
                'debit' => 0,
                'credit' => $amount,
            ]));

            if (!$debit || !$credit) {
                throw new \RuntimeException("Failed to write debit/credit ledger entries for transaction ID: {$transactionId}");
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logError('ledger.record_double_entry.failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function verifyTransactionBalance(string $transactionId): bool
    {
        $stmt = $this->db->prepare("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM ledger_entries WHERE transaction_id = ?");
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch();
        if (!$row) return true;

        $debit = (float) ($row['total_debit'] ?? 0);
        $credit = (float) ($row['total_credit'] ?? 0);

        return bccomp((string)$debit, (string)$credit, 8) === 0;
    }

    public function isLedgerBalanced(): bool
    {
        $stmt = $this->db->query("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM ledger_entries");
        $row = $stmt->fetch();
        if (!$row) return true;

        $debit = (float) ($row['total_debit'] ?? 0);
        $credit = (float) ($row['total_credit'] ?? 0);

        return bccomp((string)$debit, (string)$credit, 8) === 0;
    }
}
