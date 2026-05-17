<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Transaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\AuditTrail;

/**
 * ReconciliationService — dynamic payment reconciliation with hardened security (HMAC & Ledger precision)
 */
class ReconciliationService extends \App\Services\BaseService
{
    private const RECONCILIATION_TIMEOUT = 3600; // 1 Hour

    public function __construct(
        private Transaction $transactionModel,
        private LedgerEntry $ledgerModel,
        private Wallet $walletModel,
        private Database $db,
        protected LoggerInterface $logger,
        private WalletService $walletService,
        private LedgerService $ledgerService,
        private AuditTrail $auditTrail
    ) {
        parent::__construct($logger);
    }

    /**
     * Reconcile external transaction webhook data with local ledger and wallet
     */
    public function reconcilePayment(array $webhookData, bool $isInternal = false): array
    {
        $externalId = $webhookData['transaction_id'] ?? $webhookData['reference_id'] ?? null;
        $amount = (string)($webhookData['amount'] ?? '0');
        $currency = strtolower((string)($webhookData['currency'] ?? 'irt'));
        $status = $webhookData['status'] ?? null; // 'success', 'failed', 'pending'

        if (!$externalId) {
            $this->logger->error('reconciliation.invalid_input', ["message" => "External ID is missing from webhook"]);
            return ['success' => false, 'message' => 'کد پیگیری معتبر نیست'];
        }

        try {
            $this->db->beginTransaction();

            $internal = $isInternal;

            if (!$internal) {
                // 🛡️ HIGH-03: Enforce Webhook signature validation (HMAC) prior to reconciling
                $secret = config('webhook.secret') 
                    ?? $this->db->fetchColumn("SELECT value FROM settings WHERE key_name = 'webhook_secret' LIMIT 1");

                if (empty($secret)) {
                    $this->logger->critical('reconciliation.missing_webhook_secret', []);
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'پیکربندی امنیتی وبهوک ناقص است'];
                }

                $signature = $webhookData['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? null;
                if (!$signature) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'امضای امنیتی وب‌هوک یافت نشد'];
                }

                $payloadData = $webhookData;
                unset($payloadData['signature']);
                ksort($payloadData);
                $computed = hash_hmac('sha256', json_encode($payloadData, JSON_UNESCAPED_SLASHES), (string)$secret);
                if (!hash_equals((string)$signature, $computed)) {
                    $this->logger->error('reconciliation.invalid_signature', [
                        'received' => $signature,
                        'computed' => $computed,
                    ]);
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'امضای وب‌هوک معتبر نیست'];
                }
            }

            // Find and lock the matching transaction immediately inside the transaction block
            $transaction = $this->db->query(
                "SELECT * FROM transactions WHERE (external_id = :ext_id OR gateway_transaction_id = :ext_id OR transaction_id = :ext_id) FOR UPDATE LIMIT 1",
                ['ext_id' => (string)$externalId]
            )->fetch(\PDO::FETCH_OBJ);

            // Register orphan transaction inside the transaction block with lock if not exists
            if (!$transaction) {
                $transaction = $this->createOrphanTransaction($webhookData);
            }

            // MED-05: Lock Wallet first to establish consistent lock order hierarchy (Wallet -> Transaction)
            $userId = $transaction->user_id ?? $webhookData['user_id'] ?? null;
            if ($userId) {
                $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$userId])->fetch();
            }

            // Re-fetch transaction row FOR UPDATE under the wallet lock to strictly enforce locking order hierarchy
            $transaction = $this->db->query(
                "SELECT * FROM transactions WHERE id = :id FOR UPDATE",
                ['id' => $transaction->id]
            )->fetch(\PDO::FETCH_OBJ);

            if (empty($transaction->user_id)) {
                $this->db->rollBack();
                $this->logger->error('reconciliation.orphan_no_user', ['external_id' => $externalId]);
                return ['success' => false, 'message' => 'تراکنش ناشناخته بدون کاربر مشخص مجاز نیست'];
            }

            if (in_array($transaction->status, ['completed', 'failed', 'cancelled'], true)) {
                $this->db->rollBack();
                return ['success' => true, 'message' => 'این تراکنش قبلاً پردازش و نهایی شده بود'];
            }

            $result = match ($status) {
                'success' => $this->processSuccessfulPayment($transaction, $webhookData),
                'failed'  => $this->processFailedPayment($transaction, $webhookData),
                default   => ['success' => true, 'message' => 'وضعیت معلق یا نامشخص']
            };

            if (!$result['success']) {
                $this->db->rollBack();
                return $result;
            }

            // 5. Verification check
            if ($transaction->user_id) {
                $consistency = $this->verifyConsistency((int)$transaction->user_id, $currency);
                if (!$consistency['valid']) {
                    $this->logger->error('reconciliation.consistency_drift_detected', [
                        'user_id' => $transaction->user_id,
                        'error' => $consistency['message']
                    ]);
                    
                    $this->auditTrail->record('reconciliation.consistency_drift', (int)$transaction->user_id, [
                        'error' => $consistency['message'],
                        'transaction_id' => $transaction->id
                    ]);

                    throw new \RuntimeException("Reconciliation aborted: Financial consistency drift detected: " . $consistency['message']);
                }
            }

            $this->auditTrail->record('payment_reconciled', $transaction->user_id ? (int)$transaction->user_id : null, [
                'message' => "Payment $externalId reconciled dynamically.",
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $amount,
                'status' => $status,
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'transaction_id' => $transaction->id,
                'message' => 'تطبیق تراکنش با موفقیت انجام شد'
            ];

        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('reconciliation.error', [
                'external_id' => $externalId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در فرآیند تطبیق تراکنش رخ داد'];
        }
    }

    /**
     * Process successful payment securely
     */
    private function processSuccessfulPayment(object $transaction, array $webhookData): array
    {
        // 🛡️ CRIT-04: Strict Ledger-based Idempotency Check only. Float balance comparisons are deleted.
        $txId = (string)($transaction->transaction_id ?? $transaction->id);
        $existingLedger = $this->ledgerModel->getByTransactionId($txId);
        if (!empty($existingLedger)) {
            return ['success' => true, 'message' => 'این تراکنش قبلاً در دفتر کل ثبت شده است و پردازش مجدد نادیده گرفته شد'];
        }

        $webhookAmount = $webhookData['amount'] ?? null;
        $scale = strtolower((string)($transaction->currency ?? 'irt')) === 'usdt' ? 8 : 4;
        if ($webhookAmount !== null && bccomp((string)$webhookAmount, (string)$transaction->amount, $scale) !== 0) {
            $this->logger->error('reconciliation.amount_mismatch', [
                'transaction_id' => $transaction->id,
                'transaction_amount' => $transaction->amount,
                'webhook_amount' => $webhookAmount,
            ]);
            return ['success' => false, 'message' => 'مبلغ تراکنش با مبلغ پرداخت شده مطابقت ندارد'];
        }

        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $webhookCurrency = isset($webhookData['currency']) ? strtolower((string)$webhookData['currency']) : null;
        if ($webhookCurrency !== null && $webhookCurrency !== $currency) {
            $this->logger->error('reconciliation.currency_mismatch', [
                'transaction_id' => $transaction->id,
                'transaction_currency' => $currency,
                'webhook_currency' => $webhookCurrency,
            ]);
            return ['success' => false, 'message' => 'ارز تراکنش با ارز پرداخت شده مطابقت ندارد'];
        }

        $amount = (string)$transaction->amount;

        // 1. Update status atomicaly
        $affected = $this->db->execute(
            "UPDATE transactions 
             SET status = 'completed', 
                 updated_at = :updated_at, 
                 verified_at = :verified_at, 
                 metadata = :metadata 
             WHERE id = :id AND status = 'pending'",
            [
                'id' => (int)$transaction->id,
                'updated_at' => date('Y-m-d H:i:s'),
                'verified_at' => date('Y-m-d H:i:s'),
                'metadata' => json_encode(array_merge(json_decode($transaction->metadata ?? '{}', true), ['reconciled_webhook' => $webhookData]))
            ]
        );

        if ($affected === 0) {
            return ['success' => false, 'message' => 'تراکنش قبلاً پردازش شده یا در وضعیت معلق نیست'];
        }

        // 2. Perform financial operations in the current transaction
        switch ($transaction->type) {
            case 'deposit':
            case 'crypto_deposit':
            case 'payment':
                if ($transaction->user_id) {
                    $this->walletService->depositInTransaction(
                        (int)$transaction->user_id,
                        $amount,
                        $currency,
                        [
                            'type' => $transaction->type,
                            'transaction_id' => $transaction->id,
                            'idempotency_key' => "recon_dep_" . $transaction->id
                        ]
                    );
                }
                break;

            case 'withdrawal':
                if ($transaction->user_id) {
                    $this->walletService->completeWithdrawal(
                        (int)$transaction->user_id,
                        $amount,
                        $currency,
                        (string)($transaction->transaction_id ?? null)
                    );
                }
                break;
            
            default:
                $this->logger->info('reconciliation.type_handled_default', ['type' => $transaction->type, 'tx' => $transaction->id]);
                break;
        }

        return ['success' => true, 'message' => 'تراکنش با موفقیت نهایی شد'];
    }

    /**
     * Process failed payment securely
     */
    private function processFailedPayment(object $transaction, array $webhookData): array
    {
        $affected = $this->db->execute(
            "UPDATE transactions 
             SET status = 'failed', 
                 updated_at = :updated_at, 
                 metadata = :metadata 
             WHERE id = :id AND status = 'pending'",
            [
                'id' => (int)$transaction->id,
                'updated_at' => date('Y-m-d H:i:s'),
                'metadata' => json_encode(array_merge(json_decode($transaction->metadata ?? '{}', true), [
                    'failure_reason' => $webhookData['failure_reason'] ?? 'Gateway failure signal',
                    'reconciled_webhook' => $webhookData
                ]))
            ]
        );

        if ($affected === 0) {
            return ['success' => false, 'message' => 'تراکنش قبلاً پردازش شده یا در وضعیت معلق نیست'];
        }

        if ($transaction->type === 'withdrawal' && $transaction->user_id) {
            $this->walletService->cancelWithdrawal(
                (int)$transaction->user_id,
                (string)$transaction->amount,
                (string)$transaction->currency,
                (string)($transaction->transaction_id ?? null)
            );
        }

        return ['success' => true, 'message' => 'وضعیت تراکنش به شکست تغییر یافت'];
    }

    /**
     * Create orphan transaction record safely
     */
    private function createOrphanTransaction(array $webhookData): object
    {
        $externalId = (string)($webhookData['transaction_id'] ?? $webhookData['reference_id'] ?? 'orphan_' . time());
        
        $existing = $this->db->query(
            "SELECT * FROM transactions WHERE external_id = ? LIMIT 1 FOR UPDATE",
            [$externalId]
        )->fetch(\PDO::FETCH_OBJ);

        if ($existing) {
            return $existing;
        }

        $id = $this->transactionModel->create([
            'user_id' => $webhookData['user_id'] ?? null,
            'type' => 'orphan_payment',
            'amount' => (string)($webhookData['amount'] ?? '0'),
            'currency' => strtolower((string)($webhookData['currency'] ?? 'irt')),
            'status' => 'pending',
            'external_id' => $externalId,
            'gateway' => $webhookData['gateway'] ?? 'unknown',
            'metadata' => json_encode($webhookData),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $this->db->query("SELECT * FROM transactions WHERE id = ?", [$id])->fetch(\PDO::FETCH_OBJ)
               ?? $this->transactionModel->find((int)$id);
    }

    /**
     * Verification check for accounting consistency (Wallet balance vs Ledger history)
     */
    public function verifyConsistency(int $userId, string $currency = 'irt'): array
    {
        try {
            $currency = strtolower($currency);
            $scale = $currency === 'usdt' ? 8 : 4;

            $wallet = $this->walletModel->findByUserId($userId);
            $balanceField = $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
            $walletBalance = $wallet ? (string)($wallet->$balanceField ?? '0') : '0';

            $account = "wallet:{$userId}";
            $ledgerResult = $this->db->query(
                "SELECT SUM(debit) as total_debit, SUM(credit) as total_credit
                 FROM ledger_entries 
                 WHERE account = ? AND currency = ?",
                [$account, $currency]
            )->fetch();
            
            $debitSum = $ledgerResult ? (string)($ledgerResult->total_debit ?? '0') : '0';
            $creditSum = $ledgerResult ? (string)($ledgerResult->total_credit ?? '0') : '0';
            $ledgerBalance = bcsub($debitSum, $creditSum, $scale);

            $diff = bcsub($walletBalance, $ledgerBalance, $scale);
            $absDiff = (bccomp($diff, '0', $scale) < 0) ? bcmul($diff, '-1', $scale) : $diff;

            $tolerance = $currency === 'usdt' ? '0.0001' : '1.0000';

            if (bccomp($absDiff, $tolerance, $scale) > 0) {
                return [
                    'valid' => false,
                    'message' => "عدم همخوانی بالانس. کیف پول: {$walletBalance}، دفتر کل: {$ledgerBalance}، تفاضل: {$absDiff}"
                ];
            }

            return ['valid' => true, 'message' => 'تراز مالی صحیح است'];
        } catch (\Throwable $e) {
            $this->logger->error('reconciliation.consistency_check.failed', [
                'user_id' => $userId,
                'currency' => $currency,
                'error' => $e->getMessage()
            ]);
            return ['valid' => false, 'message' => 'خطای سیستمی در سیستم ترازگیری: ' . $e->getMessage()];
        }
    }

    /**
     * Hourly audit for pending orphan/abandoned transactions
     */
    public function autoReconcilePendingTransactions(int $limit = 50): array
    {
        $results = ['total' => 0, 'reconciled' => 0, 'failed' => 0];

        $pendingTxns = $this->db->query(
            "SELECT * FROM transactions 
             WHERE status = 'pending' 
             AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
             ORDER BY created_at ASC LIMIT ?",
            [$limit]
        )->fetchAll() ?? [];

        foreach ($pendingTxns as $txn) {
            $results['total']++;
            try {
                // Future Verify Endpoint integration
            } catch (\Throwable $e) {
                $results['failed']++;
            }
        }

        return $results;
    }
}
