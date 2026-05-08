<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\ReferralCommission;
use Core\Database;
use App\Contracts\LoggerInterface;
/**
 * ReconciliationService — خودکار Payment Reconciliation
 * 
 * یہ سرویس payment gateway webhooks کو orders/wallets/commissions کے ساتھ ہم آہنگ کرتی ہے۔
 * 
 * فعالیت:
 * - Webhook سے order تلاش کرنا
 * - تمام متعلقہ entities کو atomically آپڈیٹ کرنا
 * - Consistency verification
 * - Scheduled reconciliation
 */
class ReconciliationServiceextends \App\Services\BaseService
{
    private const RECONCILIATION_TIMEOUT = 3600; // 1 گھنٹہ

    public function __construct(
        private Order $orderModel,
        private Transaction $transactionModel,
        private LedgerEntry $ledgerModel,
        private Wallet $walletModel,
        private Database $db,
        protected LoggerInterface $logger,
        private WalletService $walletService,
        private LedgerService $ledgerService,
        private ReferralCommissionService $commissionService,
        private AuditTrail $auditTrail
    ) {}

    /**
     * Payment webhook کو reconcile کریں
     * 
     * @param array $webhookData Payment gateway سے ڈیٹا
     * @return array ['success' => bool, 'order_id' => int|null, 'message' => string]
     */
    public function reconcilePayment(array $webhookData): array
    {
        // Webhook سے ضروری معلومات نکالیں
        $externalId = $webhookData['transaction_id'] ?? $webhookData['reference_id'] ?? null;
        $amount = (float) ($webhookData['amount'] ?? 0);
        $currency = $webhookData['currency'] ?? 'irt';
        $status = $webhookData['status'] ?? null; // 'success', 'failed', 'pending'

        if (!$externalId || !$amount) {
            $this->logger->error('reconciliation_invalid', "Invalid webhook data");
            return ['success' => false, 'message' => 'ناقص webhook ڈیٹا'];
        }

        try {
            $this->db->beginTransaction();

            // وجود والا ٹرانزیکشن تلاش کریں
            $transaction = $this->db->query(
                "SELECT * FROM transactions WHERE external_id = ? LIMIT 1",
                [$externalId]
            )->fetch();

            if (!$transaction) {
                // نیا ٹرانزیکشن بنائیں (unknown payment)
                $transaction = $this->createOrphanTransaction($webhookData);
            }

            // Order تلاش کریں
            $order = $this->db->query(
                "SELECT * FROM orders WHERE transaction_id = ? OR reference_id = ? LIMIT 1",
                [$transaction->id, $externalId]
            )->fetch();

            if (!$order) {
                $this->db->rollBack();
                $this->logger->warning('reconciliation_order_not_found', "Order not found for transaction $externalId");
                return ['success' => false, 'message' => 'Order نہیں ملا'];
            }

            // Status کی بنیاد پر process کریں
            if ($status === 'success') {
                $result = $this->processSuccessfulPayment($transaction, $order, $webhookData);
            } elseif ($status === 'failed') {
                $result = $this->processFailedPayment($transaction, $order, $webhookData);
            } else {
                $result = ['success' => true, 'message' => 'Payment معلق ہے'];
            }

            if (!$result['success']) {
                $this->db->rollBack();
                return $result;
            }

            // Consistency check کریں
            $consistency = $this->verifyConsistency($transaction->user_id, $currency);
            if (!$consistency['valid']) {
                $this->logger->error('reconciliation_consistency_failed', $consistency['message']);
                $this->db->rollBack();
                return ['success' => false, 'message' => 'ڈیٹا میں عدم مطابقت'];
            }

            // Audit trail
            $this->auditTrail->log('payment_reconciled', "Payment $externalId reconciled for order {$order->id}", [
                'external_id' => $externalId,
                'order_id' => $order->id,
                'amount' => $amount,
                'status' => $status,
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'order_id' => $order->id,
                'message' => 'Payment کامیابی سے ہم آہنگ ہوا'
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('reconciliation_error', $e->getMessage());
            return ['success' => false, 'message' => 'خرابی: ' . $e->getMessage()];
        }
    }

    /**
     * کامیاب payment کو process کریں
     */
    private function processSuccessfulPayment($transaction, $order, $webhookData): array
    {
        $amount = (float) $webhookData['amount'];
        $currency = $webhookData['currency'] ?? 'irt';

        // Transaction کو update کریں
        $this->transactionModel->update($transaction->id, [
            'status' => 'completed',
            'external_status' => 'success',
            'webhook_received_at' => date('Y-m-d H:i:s'),
        ]);

        // Order کو update کریں
        $this->db->query(
            "UPDATE orders SET status = 'paid', paid_at = NOW(), payment_verified = 1 WHERE id = ?",
            [$order->id]
        );

        // Ledger میں entry شامل کریں (اگر ابھی نہیں ہے)
        if ($order->type === 'withdrawal') {
            // Withdrawal کا کمیشن شامل کریں
            $commission = $amount * 0.02; // 2% commission
            $netAmount = $amount - $commission;

            $this->ledgerService->recordDoubleEntry(
                $order->user_id,
                $netAmount,
                $commission,
                'irt',
                'withdrawal_complete',
                ['order_id' => $order->id]
            );
        } elseif ($order->type === 'deposit') {
            // Deposit
            $this->walletService->deposit(
                $order->user_id,
                $amount,
                $currency,
                [
                    'type' => 'deposit',
                    'order_id' => $order->id,
                    'idempotency_key' => "order_" . $order->id,
                ]
            );
        }

        // Referral commission شامل کریں (اگر applicable ہو)
        if ($order->referrer_id) {
            $this->commissionService->processCommission(
                $order->referrer_id,
                $amount,
                $currency,
                ['type' => 'order_commission', 'order_id' => $order->id]
            );
        }

        return ['success' => true, 'message' => 'کامیاب payment process ہوا'];
    }

    /**
     * ناکام payment کو process کریں
     */
    private function processFailedPayment($transaction, $order, $webhookData): array
    {
        // Transaction کو update کریں
        $this->transactionModel->update($transaction->id, [
            'status' => 'failed',
            'external_status' => 'failed',
            'failed_reason' => $webhookData['failure_reason'] ?? 'Unknown',
            'webhook_received_at' => date('Y-m-d H:i:s'),
        ]);

        // Order کو update کریں
        $this->db->query(
            "UPDATE orders SET status = 'payment_failed', failed_at = NOW() WHERE id = ?",
            [$order->id]
        );

        // اگر balance locked تھا تو unlock کریں
        if ($order->type === 'withdrawal') {
            $amount = (float) $webhookData['amount'];
            $currency = $webhookData['currency'] ?? 'irt';

            $this->walletService->unlockBalance(
                $order->user_id,
                $amount,
                $currency
            );
        }

        return ['success' => true, 'message' => 'ناکام payment record کیا گیا'];
    }

    /**
     * Orphan transaction بنائیں (unknown payment)
     */
    private function createOrphanTransaction(array $webhookData): object
    {
        $externalId = $webhookData['transaction_id'] ?? $webhookData['reference_id'];
        
        $this->transactionModel->create([
            'user_id' => null, // Unknown user
            'type' => 'orphan_payment',
            'amount' => (float) $webhookData['amount'],
            'currency' => $webhookData['currency'] ?? 'irt',
            'status' => 'pending',
            'external_id' => $externalId,
            'metadata' => json_encode($webhookData),
        ]);

        return $this->db->query(
            "SELECT * FROM transactions WHERE external_id = ? LIMIT 1",
            [$externalId]
        )->fetch();
    }

    /**
     * Data consistency verify کریں
     * 
     * لیجر entries = wallet balance
     * ٹرانزیکشنز = orders
     */
    public function verifyConsistency(int $userId, string $currency = 'irt'): array
    {
        try {
            // Wallet balance
            $wallet = $this->walletModel->findByUser($userId, $currency);
            $walletBalance = $wallet ? $wallet->balance : 0;

            // Ledger سے total
            $ledgerResult = $this->db->query(
                "SELECT (SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) - 
                          SUM(CASE WHEN type = 'debit' THEN amount ELSE 0 END)) as balance
                 FROM ledger_entries 
                 WHERE user_id = ? AND currency = ? AND deleted_at IS NULL",
                [$userId, $currency]
            )->fetch();
            
            $ledgerBalance = $ledgerResult ? (float) $ledgerResult->balance : 0;

            // Double-entry integrity check
            $creditSum = $this->db->query(
                "SELECT SUM(debit_amount) as total FROM ledger_entries WHERE user_id = ? AND currency = ?",
                [$userId, $currency]
            )->fetch()->total ?? 0;

            $debitSum = $this->db->query(
                "SELECT SUM(credit_amount) as total FROM ledger_entries WHERE user_id = ? AND currency = ?",
                [$userId, $currency]
            )->fetch()->total ?? 0;

            if ((float) $creditSum !== (float) $debitSum) {
                return [
                    'valid' => false,
                    'message' => "Ledger imbalance: credits=$creditSum, debits=$debitSum"
                ];
            }

            // Tolerance
            $tolerance = 1; // 1 تومان tolerance
            if (abs($walletBalance - $ledgerBalance) > $tolerance) {
                return [
                    'valid' => false,
                    'message' => "Balance mismatch: wallet=$walletBalance, ledger=$ledgerBalance"
                ];
            }

            return ['valid' => true, 'message' => 'Data consistent'];
        } catch (\Exception $e) {
            return ['valid' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * معلق payments کو reconcile کریں (Scheduled Job)
     */
    public function reconcilePendingPayments(): array
    {
        $results = [
            'total' => 0,
            'reconciled' => 0,
            'failed' => 0,
            'errors' => []
        ];

        // Timeout سے پہلے والے معلق transactions
        $transactions = $this->db->query(
            "SELECT t.*, o.id as order_id FROM transactions t
             LEFT JOIN orders o ON t.id = o.transaction_id
             WHERE t.status = 'pending' 
             AND t.type IN ('payment', 'deposit')
             AND UNIX_TIMESTAMP(t.created_at) < UNIX_TIMESTAMP(NOW()) - ?
             LIMIT 100",
            [self::RECONCILIATION_TIMEOUT]
        )->fetchAll();

        foreach ($transactions as $transaction) {
            $results['total']++;

            try {
                // Payment gateway سے latest status check کریں (اگر implementation موجود ہے)
                $webData = [
                    'transaction_id' => $transaction->external_id,
                    'amount' => $transaction->amount,
                    'currency' => $transaction->currency,
                    'status' => 'pending', // Default
                ];

                $result = $this->reconcilePayment($webData);
                if ($result['success']) {
                    $results['reconciled']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = $result['message'];
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = $e->getMessage();
            }
        }

        $this->logger->info('reconciliation_scheduled', "Processed {$results['total']} pending payments");

        return $results;
    }

    /**
     * تمام unreconciled transactions دیکھیں
     */
    public function getUnreconciledTransactions(int $limit = 50): array
    {
        return $this->db->query(
            "SELECT t.*, o.id as order_id FROM transactions t
             LEFT JOIN orders o ON t.id = o.transaction_id
             WHERE t.status = 'pending'
             AND UNIX_TIMESTAMP(t.created_at) < UNIX_TIMESTAMP(NOW()) - ?
             ORDER BY t.created_at ASC
             LIMIT ?",
            [self::RECONCILIATION_TIMEOUT, $limit]
        )->fetchAll() ?? [];
    }
}

