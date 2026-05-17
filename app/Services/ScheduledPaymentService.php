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
        $processed = 0;
        $failed = 0;
        $details = [];

        $startedTransaction = !$this->db->inTransaction();
        if ($startedTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $due = $this->scheduledPaymentModel->getDuePayments($limit);

            foreach ($due as $payment) {
                $this->db->getPdo()->exec("SAVEPOINT sp_payment_" . $payment->id);
                try {
                    if ($this->walletService->isWalletFrozen((int)$payment->user_id)) {
                        $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'paused');
                        $details[] = ['id' => $payment->id, 'status' => 'paused', 'reason' => 'wallet_frozen'];
                        $failed++;
                        $this->db->getPdo()->exec("RELEASE SAVEPOINT sp_payment_" . $payment->id);
                        continue;
                    }

                    // 🔒 Lock the wallet row to serialize concurrent balance checks and withdrawals (prevent TOCTOU)
                    $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$payment->user_id])->fetch();

                    if (!$this->walletService->hasBalance((int)$payment->user_id, (string)$payment->amount, $payment->currency)) {
                        $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');
                        $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => 'insufficient_funds'];
                        $failed++;
                        $this->db->getPdo()->exec("RELEASE SAVEPOINT sp_payment_" . $payment->id);
                        continue;
                    }

                    // 🔒 FIXED SECURITY VULNERABILITY: Replaced manual unsafe balance decrement
                    // with robust, auditable atomic withdraw mechanism via unified WalletService.
                    $txId = $this->walletService->withdraw(
                        (int)$payment->user_id,
                        (string)$payment->amount,
                        $payment->currency,
                        [
                            'type' => 'scheduled_payment',
                            'description' => $payment->description ?? 'Scheduled payment charge',
                            'scheduled_payment_id' => $payment->id,
                            'idempotency_key' => hash('sha256', 'sched_payment|' . $payment->id . '|' . $payment->next_run_at)
                        ]
                    );

                    if (empty($txId) || !is_array($txId) || empty($txId['success']) || empty($txId['transaction_id'])) {
                        throw new \RuntimeException('Failed to execute atomic wallet withdrawal: ' . ($txId['message'] ?? 'Unknown error'));
                    }

                    $nextRun = $this->calculateNextRun((string)$payment->frequency, (string)$payment->next_run_at);
                    $status = $payment->frequency === 'one_time' ? 'completed' : 'active';
                    $this->scheduledPaymentModel->updateNextRun((int)$payment->id, $nextRun, $status);

                    $this->db->getPdo()->exec("RELEASE SAVEPOINT sp_payment_" . $payment->id);

                    // ✅ **تطبیق scheduled payment با wallet و ledger**
                    // تأیید: آیا scheduled payment واقعاً از wallet کاهش پیدا کرد؟
                    try {
                        $reconciliation = $this->reconciliationService->verifyConsistency(
                            (int)$payment->user_id,
                            (string)$payment->currency
                        );

                        if (!$reconciliation['valid']) {
                            $this->logger->warning('scheduled_payment.reconciliation_failed', [
                                'payment_id' => $payment->id,
                                'user_id' => $payment->user_id,
                                'amount' => $payment->amount,
                                'message' => $reconciliation['message'] ?? 'Unknown consistency error',
                            ]);
                        }
                    } catch (\Throwable $reconcileEx) {
                        $this->logger->error('scheduled_payment.reconciliation_exception', [
                            'payment_id' => $payment->id,
                            'error' => $reconcileEx->getMessage()
                        ]);
                    }

                    $processed++;
                    $details[] = ['id' => $payment->id, 'status' => $status];
                } catch (\Exception $e) {
                    $this->db->getPdo()->exec("ROLLBACK TO SAVEPOINT sp_payment_" . $payment->id);
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

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
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
