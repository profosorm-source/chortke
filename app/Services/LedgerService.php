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
        string $amount,
        string $currency = 'irt',
        string $description = null,
        array $metadata = []
    ): bool {
        if (bccomp($amount, '0', 8) <= 0) {
            return false;
        }

        if (!$this->db->inTransaction()) {
            throw new \RuntimeException(
                'recordDoubleEntry MUST be called within an active transaction'
            );
        }

        try {
            $common = [
                'transaction_id' => $transactionId,
                'description' => $description,
                'metadata' => $metadata,
            ];

            $debit = $this->recordEntry(array_merge($common, [
                'account' => $debitAccount,
                'debit' => $amount,
                'credit' => 0,
                'currency' => $currency,
            ]));

            $credit = $this->recordEntry(array_merge($common, [
                'account' => $creditAccount,
                'debit' => 0,
                'credit' => $amount,
                'currency' => $currency,
            ]));

            if (!$debit || !$credit) {
                throw new \RuntimeException("Failed to write debit/credit ledger entries for transaction ID: {$transactionId}");
            }

            return true;
        } catch (\Throwable $e) {
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
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$row) return true;

        $debit = (string) ($row->total_debit ?? '0');
        $credit = (string) ($row->total_credit ?? '0');

        return bccomp($debit, $credit, 8) === 0;
    }

    public function isLedgerBalanced(): bool
    {
        $stmt = $this->db->query("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM ledger_entries");
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$row) return true;

        $debit = (string) ($row->total_debit ?? '0');
        $credit = (string) ($row->total_credit ?? '0');

        return bccomp($debit, $credit, 8) === 0;
    }

    public function getAccountBalance(string $account, string $currency = 'irt'): string
    {
        $currency = strtolower($currency);
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit FROM ledger_entries WHERE account = ? AND currency = ?");
        $stmt->execute([$account, $currency]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        $scale = $currency === 'usdt' ? 8 : 4;
        return bcsub((string)($row->total_debit ?? '0'), (string)($row->total_credit ?? '0'), $scale);
    }

    public function findImbalancedTransactions(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->db->fetchAll(
            "SELECT transaction_id, currency, COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit, COUNT(*) AS legs
             FROM ledger_entries
             GROUP BY transaction_id, currency
             HAVING ABS(total_debit - total_credit) > 0.00000001
             ORDER BY MAX(created_at) DESC
             LIMIT {$limit}"
        );
    }

    public function findByTransactionId(string $transactionId): array
    {
        return $this->ledgerEntry->getByTransactionId($transactionId);
    }
}
