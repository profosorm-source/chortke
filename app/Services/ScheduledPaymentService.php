<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Models\ScheduledPayment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Contracts\LoggerInterface;

class ScheduledPaymentService extends \App\Services\BaseService
{

    public function __construct(
        private ScheduledPayment $scheduledPaymentModel,
        private WalletService $walletService,
        private Database $db,
        LoggerInterface $logger,
        private ReconciliationService $reconciliationService
    ) {
        parent::__construct($logger);
    }

    public function createSchedule(array $data): ?object
    {
        if (empty($data['user_id']) || empty($data['amount']) || empty($data['next_run_at'])) {
            return null;
        }

        return $this->scheduledPaymentModel->createSchedule($data);
    }

    public function processDuePayments(int $limit = 50): array
    {
        $due = $this->scheduledPaymentModel->getDuePayments($limit);
        $processed = 0;
        $failed = 0;
        $details = [];

        foreach ($due as $payment) {
            try {
                if ($this->walletService->isWalletFrozen((int)$payment->user_id)) {
                    $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'paused');
                    $details[] = ['id' => $payment->id, 'status' => 'paused', 'reason' => 'wallet_frozen'];
                    $failed++;
                    continue;
                }

                if (!$this->walletService->hasBalance((int)$payment->user_id, (float)$payment->amount, $payment->currency)) {
                    $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');
                    $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => 'insufficient_funds'];
                    $failed++;
                    continue;
                }

                $this->db->beginTransaction();

                // 🔒 FIXED SECURITY VULNERABILITY: Replaced manual unsafe balance decrement
                // with robust, auditable atomic withdraw mechanism via unified WalletService.
                $txId = $this->walletService->withdraw(
                    (int)$payment->user_id,
                    (float)$payment->amount,
                    $payment->currency,
                    [
                        'type' => 'scheduled_payment',
                        'description' => $payment->description ?? 'Scheduled payment charge',
                        'scheduled_payment_id' => $payment->id,
                        'idempotency_key' => \Core\IdempotencyKey::generateFromPayload('sched_payment', [
                            'payment_id' => $payment->id,
                            'timestamp'  => time()
                        ])
                    ]
                );

                if (!$txId) {
                    throw new \RuntimeException('Failed to execute atomic wallet withdrawal');
                }

                $nextRun = $this->calculateNextRun((string)$payment->frequency, (string)$payment->next_run_at);
                $status = $payment->frequency === 'one_time' ? 'completed' : 'active';
                $this->scheduledPaymentModel->updateNextRun((int)$payment->id, $nextRun, $status);

                // ✅ **تطبیق scheduled payment با wallet و ledger**
                // تأیید: آیا scheduled payment واقعاً از wallet کاهش پیدا کرد؟
                $reconciliation = $this->reconciliationService->reconcilePayment([
                    'transaction_id' => 'scheduled_' . $txId,
                    'reference_id' => 'scheduled_payment_' . $payment->id,
                    'user_id' => (int)$payment->user_id,
                    'amount' => (float)$payment->amount,
                    'currency' => $payment->currency,
                    'status' => 'success',
                    'gateway' => 'scheduled_charge',
                    'description' => "تطبیق scheduled payment - Frequency: {$payment->frequency}, Next: {$nextRun}",
                    'timestamp' => time(),
                ]);

                if (!$reconciliation['success']) {
                    $this->logger->warning('scheduled_payment.reconciliation_failed', [
                        'payment_id' => $payment->id,
                        'user_id' => $payment->user_id,
                        'amount' => $payment->amount,
                        'message' => $reconciliation['message'] ?? 'Unknown reconciliation error',
                    ]);
                }

                $this->db->commit();
                $processed++;
                $details[] = ['id' => $payment->id, 'status' => $status];
            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');
                $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => $e->getMessage()];
                $failed++;
                $this->logError('scheduled_payment.process.failed', [
                    'payment_id' => $payment->id,
                    'user_id' => $payment->user_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['processed' => $processed, 'failed' => $failed, 'details' => $details];
    }

    private function calculateNextRun(string $frequency, string $currentRun): string
    {
        $current = new \DateTimeImmutable($currentRun);

        return match (strtolower($frequency)) {
            'weekly' => $current->modify('+1 week')->format('Y-m-d H:i:s'),
            'monthly' => $current->modify('+1 month')->format('Y-m-d H:i:s'),
            'daily' => $current->modify('+1 day')->format('Y-m-d H:i:s'),
            default => $current->modify('+0 seconds')->format('Y-m-d H:i:s'),
        };
    }
}
