<?php

declare(strict_types=1);

namespace App\Jobs\Dispute;

class AdminResolveDisputeJob
{
    public function __construct(
        private \App\Models\Dispute $disputeModel,
        private \App\Contracts\LoggerInterface $logger,
        private \App\Models\Transaction $transactionModel,
        private \App\Contracts\WalletServiceInterface $walletService,
        private \Core\TransactionWrapper $transactionWrapper
    ) {}

    public function handle(int $disputeId, int $adminId, string $verdict, string $note, float $refundPercent = 0): array
    {
        // 🔒 Hardened Fix: Wrapping entire multi-step resolver in an Atomic DB Transaction
        return $this->transactionWrapper->runWithRetry(function() use ($disputeId, $adminId, $verdict, $note, $refundPercent) {
            $dispute = $this->disputeModel->getSafe($disputeId);
            if (!$dispute) {
                return ['success' => false, 'message' => 'پرونده یافت نشد.'];
            }
            
            $ok = $this->disputeModel->update($disputeId, [
                'status' => Dispute::STATUS_RESOLVED_ADMIN,
                'admin_decision' => $verdict,
                'admin_id' => $adminId,
                'admin_note' => $note,
                'refund_percent' => $refundPercent,
                'resolved_at' => date('Y-m-d H:i:s')
            ]);
            
            if (!$ok) {
                throw new \RuntimeException('Failed to record administrative arbitration verdict.');
            }
            
            $this->logger->info('case.resolved_admin', [
                'dispute_id' => $disputeId,
                'admin_id' => $adminId,
                'verdict' => $verdict
            ]);
            
            // پردازش استرداد وجه بر اساس ردیابی زنجیره تراکنش‌های مالی
            if ($refundPercent > 0) {
                // 🔐 Safe Architectural Refactor: Swapped dynamic RAW string lookup for hard-coded Transaction model helper.
                $originalTx = $this->transactionModel->findCompletedByReference((string)$dispute->ref_id, (string)$dispute->ref_type);

                if ($originalTx && isset($originalTx->amount)) {
                    $baseAmount = abs((float)$originalTx->amount);
                    $currency = $originalTx->currency ?? 'irt';
                    $refundAmount = ($baseAmount * $refundPercent) / 100.0;

                    $success = false;

                    // سناریوی ۱: بازگشت ۱۰۰٪ وجه - استفاده از سیستم اتمیک reverse
                    if ((int)$refundPercent === 100 && method_exists($this->walletService, 'reverseTransaction')) {
                        $success = $this->walletService->reverseTransaction(
                            $originalTx->transaction_id, 
                            $adminId, 
                            "استرداد کامل (۱۰۰٪) وجه مربوط به رأی اختلاف شماره {$disputeId}"
                        );
                    } else {
                        // سناریوی ۲: بازگشت جزئی (درصدی) یا روش جایگزین
                        $payload = [
                            'user_id' => (int)$dispute->user_id,
                            'amount' => $refundAmount,
                            'currency' => $currency,
                            'metadata' => [
                                'type' => 'refund',
                                'description' => "استرداد وجه ({$refundPercent}٪) مربوط به حل اختلاف شماره {$disputeId}",
                                'ref_id' => $disputeId,
                                'ref_type' => 'dispute',
                                'admin_id' => $adminId
                            ],
                        ];

                        if ($this->outboxService) {
                            $ok = $this->outboxService->record('dispute', $disputeId, \App\Events\Registry\EventRegistry::DISPUTE_RESOLVED_REFUND, $payload);
                            $success = $ok === true;
                        } else {
                            $res = $this->walletService->deposit((int)$dispute->user_id, $refundAmount, $currency, [
                                'type' => 'refund',
                                'description' => "استرداد وجه ({$refundPercent}٪) مربوط به حل اختلاف شماره {$disputeId}",
                                'ref_id' => $disputeId,
                                'ref_type' => 'dispute',
                                'admin_id' => $adminId
                            ]);
                            $success = isset($res['success']) && $res['success'] === true;
                        }
                    }

                    if ($success) {
                        $this->logger->info('case.refund_processed', [
                            'dispute_id' => $disputeId,
                            'refund_amount' => $refundAmount,
                            'currency' => $currency,
                            'percent' => $refundPercent,
                            'user_id' => $dispute->user_id,
                            'is_reversal' => ((int)$refundPercent === 100)
                        ]);

                        // تطبیق نهایی پرداخت با دفتر کل
                        $this->reconciliationService->reconcilePayment([
                            'transaction_id' => 'dispute_refund_' . $disputeId . '_' . time(),
                            'reference_id' => 'dispute_' . $disputeId,
                            'order_id' => (int)$dispute->ref_id,
                            'amount' => $refundAmount,
                            'currency' => $currency,
                            'status' => 'success',
                            'gateway' => 'system_refund',
                            'user_id' => (int)$dispute->user_id,
                            'description' => "تطبیق خودکار استرداد رأی اختلاف",
                            'timestamp' => time(),
                            'is_internal' => true,
                        ]);
                    } else {
                        throw new \RuntimeException("Atomic dispute reversal failed at Wallet core.");
                    }
                } else {
                    $this->logger->warning('case.refund_skipped_no_tx', [
                        'dispute_id' => $disputeId,
                        'ref_id' => $dispute->ref_id,
                        'ref_type' => $dispute->ref_type,
                        'message' => 'No matching completed transaction found to derive refund amount.'
                    ]);
                }
            }
            
            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => (int)$dispute->user_id,
                'type' => 'system',
                'title' => 'رأی داوری صادر شد',
                'message' => 'داور سیستم رأی پرونده اختلاف را صادر کرد.'
            ]);
            if ($dispute->target_user_id) {
                $this->eventDispatcher->dispatchAsync('notification.requested', [
                    'user_id' => (int)$dispute->target_user_id,
                    'type' => 'system',
                    'title' => 'رأی داوری صادر شد',
                    'message' => 'داور سیستم رأی پرونده اختلاف را صادر کرد.'
                ]);
            }
            
            return ['success' => true];
        });
    }
}
