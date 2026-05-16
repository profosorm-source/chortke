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
 * ReconciliationService — خودکار Payment Reconciliation (معماری متمرکز بر تراکنش)
 * 
 * این سرویس تراکنش‌های خارجی (Webhooks) را با کیف پول و لجر داخلی هماهنگ می‌کند.
 * فاقد وابستگی به مدل‌های فرعی نظیر Order.
 */
class ReconciliationService extends \App\Services\BaseService
{
    private const RECONCILIATION_TIMEOUT = 3600; // 1 ساعت

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
     * تطبیق اطلاعات یک تراکنش خارجی با دیتابیس
     * 
     * @param array $webhookData داده‌های دریافتی از گیت‌وی یا سرویس بیرونی
     * @return array
     */
    public function reconcilePayment(array $webhookData): array
    {
        $externalId = $webhookData['transaction_id'] ?? $webhookData['reference_id'] ?? null;
        $amount = (float) ($webhookData['amount'] ?? 0);
        $currency = strtolower((string)($webhookData['currency'] ?? 'irt'));
        $status = $webhookData['status'] ?? null; // 'success', 'failed', 'pending'

        if (!$externalId) {
            $this->logger->error('reconciliation.invalid_input', ["message" => "External ID is missing from webhook"]);
            return ['success' => false, 'message' => 'کد پیگیری معتبر نیست'];
        }

        try {
            $this->db->beginTransaction();

            // ۱. یافتن تراکنش متناظر با قفل بدبینانه ردیفی جهت ممانعت از رفتارهای وب‌هوکی موازی (BUG-02)
            $transaction = $this->db->query(
                "SELECT * FROM transactions WHERE external_id = :ext_id OR gateway_transaction_id = :ext_id OR transaction_id = :ext_id LIMIT 1 FOR UPDATE",
                ['ext_id' => (string)$externalId]
            )->fetch(\PDO::FETCH_OBJ);

            // ۲. اگر تراکنش وجود نداشت، یک تراکنش یتیم/ناشناخته ثبت کن تا از هدررفت داده جلوگیری شود (BUG-14)
            if (!$transaction) {
                $transaction = $this->createOrphanTransaction($webhookData);
            }

            // H14 Fix (BUG-08): جلوگیری از ثبت موفقیت‌آمیز تراکنش‌های یتیم بدون کاربر مشخص
            if (empty($transaction->user_id)) {
                $this->db->rollBack();
                $this->logger->error('reconciliation.orphan_no_user', ['external_id' => $externalId]);
                return ['success' => false, 'message' => 'تراکنش ناشناخته بدون کاربر مشخص مجاز نیست'];
            }

            // ۳. اگر تراکنش قبلاً نهایی شده، نادیده بگیر (Idempotency)
            if (in_array($transaction->status, ['completed', 'failed', 'cancelled'])) {
                $this->db->rollBack();
                return ['success' => true, 'message' => 'این تراکنش قبلاً پردازش و نهایی شده بود'];
            }

            // ۴. پردازش بر اساس وضعیت دریافت شده
            $result = match ($status) {
                'success' => $this->processSuccessfulPayment($transaction, $webhookData),
                'failed'  => $this->processFailedPayment($transaction, $webhookData),
                default   => ['success' => true, 'message' => 'وضعیت معلق یا نامشخص']
            };

            if (!$result['success']) {
                $this->db->rollBack();
                return $result;
            }

            // ۵. بررسی یکپارچگی دیتا پس از عملیات (Consistency Check) - Non-blocking Audit
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

                    // H14 Fix (BUG-06): ابطال فوری و رول‌بک تراکنش در صورت کشف ناهمخوانی بالانس و دفتر کل جهت ممانعت از اختلال مالی
                    throw new \RuntimeException("Reconciliation aborted: Financial consistency drift detected: " . $consistency['message']);
                }
            }

            // ۶. ثبت در AuditTrail
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
     * پردازش موفقیت‌آمیز پرداخت
     */
    private function processSuccessfulPayment(object $transaction, array $webhookData): array
    {
        // جلوگیری از Double-Entry: بررسی بر اساس فیلد تراکنش و بررسی شناسه تراکنش در دفتر کل (Ledger-based Idempotency)
        $txId = (string)($transaction->transaction_id ?? $transaction->id);
        $existingLedger = $this->ledgerModel->getByTransactionId($txId);
        if (!empty($existingLedger)) {
            return ['success' => true, 'message' => 'این تراکنش قبلاً در دفتر کل ثبت شده است و پردازش مجدد نادیده گرفته شد'];
        }

        if (isset($transaction->balance_after) && isset($transaction->balance_before) && 
            (float)$transaction->balance_after > (float)$transaction->balance_before) {
            return ['success' => true, 'message' => 'Already processed'];
        }

        $amount = (float)($webhookData['amount'] ?? $transaction->amount);
        $currency = strtolower((string)($webhookData['currency'] ?? $transaction->currency ?? 'irt'));

        // ۱. آپدیت وضعیت تراکنش به کامل‌شده به صورت کاملاً اتمیک (BUG-04)
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

        // ۲. اجرای منطق حسابداری بر اساس نوع تراکنش (Business Logic)
        switch ($transaction->type) {
            case 'deposit':
            case 'crypto_deposit':
            case 'payment':
                // شارژ کیف پول درون تراکنش موجود
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
                // نهایی کردن برداشت (عموماً قبلا مبلغ قفل شده بود)
                // ثبت دوطرفه در لجر به عنوان خرج کامل شده
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
                // سایر موارد فقط وضعیت تراکنش آپدیت شد و لاگ می‌شود
                $this->logger->info('reconciliation.type_handled_default', ['type' => $transaction->type, 'tx' => $transaction->id]);
                break;
        }

        return ['success' => true, 'message' => 'تراکنش با موفقیت نهایی شد'];
    }

    /**
     * پردازش شکست پرداخت
     */
    private function processFailedPayment(object $transaction, array $webhookData): array
    {
        // ۱. آپدیت وضعیت تراکنش به شکست‌خورده به صورت کاملاً اتمیک (BUG-04)
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

        // ۲. اگر منطق خاصی برای بازگشت پول یا آنلاک کردن وجه نیاز است:
        if ($transaction->type === 'withdrawal' && $transaction->user_id) {
            // آزادسازی وجهی که برای برداشت قفل شده بود به کیف پول کاربر
            $this->walletService->cancelWithdrawal(
                (int)$transaction->user_id,
                (float)$transaction->amount,
                (string)$transaction->currency,
                (string)($transaction->transaction_id ?? null)
            );
        }

        return ['success' => true, 'message' => 'وضعیت تراکنش به شکست تغییر یافت'];
    }

    /**
     * ثبت یک تراکنش ناشناخته (برای مواردی که درگاه پرداختی فرستاده که در دیتابیس نبود)
     */
    private function createOrphanTransaction(array $webhookData): object
    {
        $externalId = (string)($webhookData['transaction_id'] ?? $webhookData['reference_id'] ?? 'orphan_' . time());
        
        // H14 Fix (BUG-14): بررسی مجدد و با قفل بدبینانه قبل از ساخت تراکنش ناشناس جهت ممانعت از درج موازی ردیف‌های یتیم تکراری
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
            'amount' => (float)($webhookData['amount'] ?? 0),
            'currency' => strtolower((string)($webhookData['currency'] ?? 'irt')),
            'status' => 'pending',
            'external_id' => $externalId,
            'gateway' => $webhookData['gateway'] ?? 'unknown',
            'metadata' => json_encode($webhookData),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // دریافت مدل ثبت شده جدید
        return $this->db->query("SELECT * FROM transactions WHERE id = ?", [$id])->fetch(\PDO::FETCH_OBJ)
               ?? $this->transactionModel->find((int)$id);
    }

    /**
     * بررسی یکپارچگی مالی کاربر (برابری بالانس کیف پول با جمع لجرها)
     */
    public function verifyConsistency(int $userId, string $currency = 'irt'): array
    {
        try {
            $currency = strtolower($currency);
            $scale = $currency === 'usdt' ? 8 : 4;

            // ۱. دریافت موجودی فعلی از کیف پول
            $wallet = $this->walletModel->findByUserId($userId);
            $balanceField = $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
            $walletBalance = $wallet ? (string)($wallet->$balanceField ?? '0') : '0';

            // ۲. دریافت جمع ریاضی تراکنش‌ها از دفتر کل (Ledger)
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

            // ۳. محاسبه تفاضل با BCMath
            $diff = bcsub($walletBalance, $ledgerBalance, $scale);
            $absDiff = (bccomp($diff, '0', $scale) < 0) ? bcmul($diff, '-1', $scale) : $diff;

            // تلورانس مجاز بر اساس ارز
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
     * سیستم ممیزی ساعتی: پیدا کردن تراکنش‌های یتیم یا رها شده و تلاش برای حل وضعیت آن‌ها
     */
    public function autoReconcilePendingTransactions(int $limit = 50): array
    {
        $results = ['total' => 0, 'reconciled' => 0, 'failed' => 0];

        // دریافت تراکنش‌های در انتظار قدیمی‌تر از یک ساعت
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
                // اینجا در سیستم واقعی می‌توان یک ریکوئست Verify به گیت‌وی مربوطه زد
                // در این مرحله ما صرفا ساختار آماده کرده‌ایم.
                // به صورت فرضی اینجا فرض می‌کنیم وریفای باید هندل شود.
            } catch (\Throwable $e) {
                $results['failed']++;
            }
        }

        return $results;
    }
}
