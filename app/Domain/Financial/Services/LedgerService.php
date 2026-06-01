<?php

declare(strict_types=1);

namespace App\Domain\Financial\Services;

use App\Models\LedgerEntry;
use Core\Database;
use App\Contracts\LoggerInterface;

class LedgerService
{
    private LedgerEntry $ledgerEntry;
    protected Database $db;
    private LoggerInterface $logger;

    public function __construct(LedgerEntry $ledgerEntry, Database $db, LoggerInterface $logger)
    {
        $this->ledgerEntry = $ledgerEntry;
        $this->db = $db;
        $this->logger = $logger;
    }

    public function recordEntry(array $data): ?object
    {
        return $this->ledgerEntry->create($data);
    }

    private function logError(string $operation, array $context = []): void
    {
        $this->logger->error($operation, $context);
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
        try {
            $stmt = $this->db->prepare("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM ledger_entries WHERE transaction_id = ?");
            $stmt->execute([$transactionId]);
            $row = $stmt->fetch(\PDO::FETCH_OBJ);
        } catch (\Throwable $e) {
            $this->logError('ledger.verify_transaction_balance.failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        if ($row === false) {
            $this->logError('ledger.verify_transaction_balance.no_rows', ['transaction_id' => $transactionId]);
            return false;
        }

        $debit = (string) ($row->total_debit ?? '0');
        $credit = (string) ($row->total_credit ?? '0');

        return \Core\ValueObjects\Money::fromString($debit)->getAmount() === \Core\ValueObjects\Money::fromString($credit)->getAmount();
    }

    public function isLedgerBalanced(): bool
    {
        try {
            $stmt = $this->db->query("SELECT SUM(debit) as total_debit, SUM(credit) as total_credit FROM ledger_entries");
            $row = $stmt->fetch(\PDO::FETCH_OBJ);
        } catch (\Throwable $e) {
            $this->logError('ledger.is_ledger_balanced.failed', ['error' => $e->getMessage()]);
            return false;
        }

        if ($row === false) {
            $this->logError('ledger.is_ledger_balanced.no_rows');
            return false;
        }

        $debit = (string) ($row->total_debit ?? '0');
        $credit = (string) ($row->total_credit ?? '0');

        return \Core\ValueObjects\Money::fromString($debit)->getAmount() === \Core\ValueObjects\Money::fromString($credit)->getAmount();
    }

    public function getAccountBalance(string $account, string $currency = 'irt'): string
    {
        $currency = strtolower($currency);
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit FROM ledger_entries WHERE account = ? AND currency = ?");
        $stmt->execute([$account, $currency]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        $scale = $currency === 'usdt' ? 8 : 4;
        return \Core\ValueObjects\Money::fromString((string)((string)($row->total_debit ?? '0')))->subtract(\Core\ValueObjects\Money::fromString((string)((string)($row->total_credit ?? '0'))))->getAmount();
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

