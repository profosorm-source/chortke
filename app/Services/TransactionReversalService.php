<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\LedgerEntry;
use App\Models\RefundLog;
use Core\Database;
use App\Contracts\LoggerInterface;
/**
 * TransactionReversalService — ٹرانزیکشن ریورسل/ریفنڈ کی سہولت
 * 
 * یہ سرویس درج ذیل کو ہینڈل کرتی ہے:
 * - پیمنٹ ریورسل (Failed Payments)
 * - Refunds (کسٹمر ریکویسٹ)
 * - Chargebacks (ڈسپیوٹ)
 * 
 * تمام ریورسلز atomically لیجر سے ریکارڈ ہوتے ہیں
 */
class TransactionReversalServiceextends \App\Services\BaseService
{
    public function __construct(
        private Transaction $transactionModel,
        private Wallet $walletModel,
        private LedgerEntry $ledgerModel,
        private Database $db,
        protected LoggerInterface $logger,
        private WalletService $walletService,
        private LedgerService $ledgerService,
        private AuditTrail $auditTrail
    ) {}

    /**
     * ٹرانزیکشن ریورس کریں
     * 
     * @param int $transactionId اصل ٹرانزیکشن کی ID
     * @param string $reason ریورسل کی وجہ (failed_payment, customer_request, technical_error)
     * @param string|null $notes اضافی نوٹس
     * @return array ['success' => bool, 'reversal_id' => int|null, 'message' => string]
     */
    public function reverse(int $transactionId, string $reason, ?string $notes = null): array
    {
        $transaction = $this->transactionModel->find($transactionId);

        if (!$transaction) {
            return ['success' => false, 'message' => 'ٹرانزیکشن نہیں ملی'];
        }

        // چیک کریں کہ یہ پہلے ریورس نہیں ہو چکا
        if ($transaction->status === 'reversed' || $transaction->status === 'refunded') {
            return ['success' => false, 'message' => 'یہ ٹرانزیکشن پہلے سے ریورس شدہ ہے'];
        }

        // صرف withdraw اور payment ٹرانزیکشنز ریورس ہو سکتی ہیں
        if (!in_array($transaction->type, ['withdraw', 'payment', 'order_payment'])) {
            return ['success' => false, 'message' => 'اس قسم کی ٹرانزیکشن ریورس نہیں کی جا سکتی'];
        }

        try {
            $this->db->beginTransaction();

            // اگر یہ withdrawal تھا تو balance واپس کریں
            if ($transaction->type === 'withdraw') {
                $refund = $this->walletService->deposit(
                    $transaction->user_id,
                    $transaction->amount,
                    $transaction->currency,
                    [
                        'type' => 'reversal',
                        'reason' => $reason,
                        'original_transaction_id' => $transactionId,
                        'idempotency_key' => "reversal_" . $transactionId . "_" . time(),
                    ]
                );

                if (!$refund) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'ریفنڈ ڈپوزٹ میں ناکام'];
                }
            }

            // Reversal کو ٹرانزیکشن میں ریکارڈ کریں
            $reversalId = $this->transactionModel->create([
                'user_id' => $transaction->user_id,
                'type' => 'reversal',
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'reason' => $reason,
                'status' => 'completed',
                'related_transaction_id' => $transactionId,
                'metadata' => json_encode([
                    'reason' => $reason,
                    'notes' => $notes,
                    'timestamp' => time(),
                    'reversed_at' => date('Y-m-d H:i:s'),
                ]),
            ]);

            if (!$reversalId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'ریورسل ریکارڈ میں ناکام'];
            }

            // اصل ٹرانزیکشن کو reversed میں نشان زد کریں
            $this->transactionModel->update($transactionId, [
                'status' => 'reversed',
                'reversal_transaction_id' => $reversalId,
            ]);

            // Audit trail میں لکھیں
            $this->auditTrail->log('transaction_reversal', "Transaction $transactionId reversed due to: $reason", [
                'original_transaction_id' => $transactionId,
                'reversal_transaction_id' => $reversalId,
                'reason' => $reason,
                'notes' => $notes,
            ]);

            $this->db->commit();

            $this->logger->info('transaction_reversal', "Transaction $transactionId reversed successfully");

            return [
                'success' => true,
                'reversal_id' => $reversalId,
                'message' => 'ٹرانزیکشن کامیابی سے ریورس ہو گئی'
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('transaction_reversal', "Reversal failed: " . $e->getMessage());
            return ['success' => false, 'message' => 'خرابی: ' . $e->getMessage()];
        }
    }

    /**
     * ریفنڈ بنائیں (کسٹمر ریکویسٹ)
     * 
     * @param int $withdrawalId withdrawal کی ID
     * @param string $reason ریفنڈ کی وجہ
     * @param float|null $amount اگر جزوی ریفنڈ ہے
     * @return array ['success' => bool, 'refund_id' => int|null, 'message' => string]
     */
    public function createRefund(int $withdrawalId, string $reason, ?float $amount = null): array
    {
        $withdrawal = $this->db->query(
            "SELECT * FROM withdrawals WHERE id = ? LIMIT 1",
            [$withdrawalId]
        )->fetch();

        if (!$withdrawal) {
            return ['success' => false, 'message' => 'Withdrawal نہیں ملی'];
        }

        if ($withdrawal->status !== 'pending' && $withdrawal->status !== 'failed') {
            return ['success' => false, 'message' => 'اس Withdrawal کو ریفنڈ نہیں کیا جا سکتا'];
        }

        $refundAmount = $amount ?? $withdrawal->amount;

        try {
            $this->db->beginTransaction();

            // واپسی ڈپوزٹ کریں
            $result = $this->walletService->deposit(
                $withdrawal->user_id,
                $refundAmount,
                $withdrawal->currency,
                [
                    'type' => 'refund',
                    'reason' => $reason,
                    'withdrawal_id' => $withdrawalId,
                    'idempotency_key' => "refund_" . $withdrawalId . "_" . time(),
                ]
            );

            if (!$result) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'ریفنڈ ڈپوزٹ میں ناکام'];
            }

            // Refund لاگ میں سیو کریں
            $refundId = $this->db->query(
                "INSERT INTO refund_logs (withdrawal_id, user_id, amount, reason, status, created_at) 
                 VALUES (?, ?, ?, ?, 'completed', NOW())",
                [$withdrawalId, $withdrawal->user_id, $refundAmount, $reason]
            ) ? $this->db->lastInsertId() : null;

            // Withdrawal کو refunded میں نشان زد کریں
            $this->db->query(
                "UPDATE withdrawals SET status = 'refunded', refunded_at = NOW(), refund_amount = ? WHERE id = ?",
                [$refundAmount, $withdrawalId]
            );

            $this->auditTrail->log('refund_created', "Refund created for withdrawal $withdrawalId", [
                'withdrawal_id' => $withdrawalId,
                'amount' => $refundAmount,
                'reason' => $reason,
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'refund_id' => $refundId,
                'message' => 'ریفنڈ کامیابی سے بنایا گیا'
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خرابی: ' . $e->getMessage()];
        }
    }

    /**
     * Chargeback ہینڈل کریں (ڈسپیوٹ/کریڈٹ کارڈ چار جیک)
     * 
     * @param string $externalId پیمنٹ گیٹ وے سے بیرونی ID
     * @param float $amount چارج بیک کی رقم
     * @param string $reason وجہ (fraud, customer_dispute, etc)
     * @return array ['success' => bool, 'message' => string]
     */
    public function handleChargeBack(string $externalId, float $amount, string $reason = 'customer_dispute'): array
    {
        // پیمنٹ لوگ میں تلاش کریں
        $paymentLog = $this->db->query(
            "SELECT * FROM payment_logs WHERE external_id = ? LIMIT 1",
            [$externalId]
        )->fetch();

        if (!$paymentLog) {
            $this->logger->warning('chargeback_not_found', "Chargeback for $externalId not found");
            return ['success' => false, 'message' => 'پیمنٹ ریکارڈ نہیں ملا'];
        }

        try {
            $this->db->beginTransaction();

            // Wallet سے رقم واپس لیں (chargeback fee)
            $totalDebit = $amount; // + chargeback fee
            
            $result = $this->walletService->withdraw(
                $paymentLog->user_id,
                $totalDebit,
                $paymentLog->currency ?? 'irt',
                [
                    'type' => 'chargeback',
                    'reason' => $reason,
                    'external_id' => $externalId,
                    'idempotency_key' => "chargeback_" . $externalId . "_" . time(),
                ]
            );

            if (!$result) {
                $this->db->rollBack();
                $this->logger->error('chargeback_failed', "Insufficient balance for chargeback");
                return ['success' => false, 'message' => 'موجودی کافی نہیں ہے'];
            }

            // Chargeback کو ریکارڈ کریں
            $this->db->query(
                "INSERT INTO chargebacks (user_id, payment_log_id, external_id, amount, reason, status, created_at)
                 VALUES (?, ?, ?, ?, ?, 'processed', NOW())",
                [$paymentLog->user_id, $paymentLog->id, $externalId, $amount, $reason]
            );

            $this->auditTrail->log('chargeback_processed', "Chargeback processed for $externalId", [
                'external_id' => $externalId,
                'amount' => $amount,
                'reason' => $reason,
                'user_id' => $paymentLog->user_id,
            ]);

            $this->db->commit();

            $this->logger->warning('chargeback_processed', "Chargeback processed for $externalId");

            return ['success' => true, 'message' => 'Chargeback کامیابی سے پروسیس ہوا'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('chargeback_error', $e->getMessage());
            return ['success' => false, 'message' => 'خرابی: ' . $e->getMessage()];
        }
    }

    /**
     * تمام ریورسلز دیکھیں (اس صارف کے لیے)
     */
    public function getReversalsForUser(int $userId): array
    {
        $reversals = $this->db->query(
            "SELECT * FROM transactions WHERE user_id = ? AND type = 'reversal' ORDER BY created_at DESC",
            [$userId]
        )->fetchAll();

        return $reversals ?? [];
    }

    /**
     * ریورسل کی تفصیلات دیکھیں
     */
    public function getReversalDetails(int $reversalId): ?array
    {
        $reversal = $this->transactionModel->find($reversalId);
        
        if (!$reversal || $reversal->type !== 'reversal') {
            return null;
        }

        $originalTransaction = $this->transactionModel->find($reversal->related_transaction_id);

        return [
            'reversal' => $reversal,
            'original_transaction' => $originalTransaction,
            'metadata' => json_decode($reversal->metadata, true) ?? [],
        ];
    }
}

