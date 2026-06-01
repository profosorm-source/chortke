<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WalletServiceInterface;
use Core\Database;
use App\Models\ScheduledPayment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Contracts\LoggerInterface;

class ScheduledPaymentService
{

    private ?\App\Domain\Financial\Services\FinancialEscrowService $escrowService = null;
private \App\Services\Shared\IdempotencyService $idempotencyService;

    private \Core\TransactionWrapper $transactionWrapper;
    private \App\Contracts\LoggerInterface $logger;
    private ScheduledPayment $scheduledPaymentModel;
    private WalletServiceInterface $walletService;
    private ReconciliationService $reconciliationService;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \App\Contracts\LoggerInterface $logger,
        ScheduledPayment $scheduledPaymentModel,
        WalletServiceInterface $walletService,
        ReconciliationService $reconciliationService,
        ?\App\Domain\Financial\Services\FinancialEscrowService $escrowService = null,
        ?\App\Contracts\ValidatorFactoryInterface $validatorFactory = null,
        ?\App\Services\Shared\IdempotencyService $idempotencyService = null
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->logger = $logger;
        $this->scheduledPaymentModel = $scheduledPaymentModel;
        $this->walletService = $walletService;
        $this->reconciliationService = $reconciliationService;

        
        $this->escrowService = $escrowService;
        $this->validatorFactory = $validatorFactory ?? \Core\Container::getInstance()->make(\App\Contracts\ValidatorFactoryInterface::class);
        $this->idempotencyService = $idempotencyService ?? \Core\Container::getInstance()->make(\App\Services\Shared\IdempotencyService::class);
    }

    public function createSchedule(array $data): ?object
    {
        $validator = $this->validatorFactory->make($data, [
            'user_id' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:1',
            'next_run_at' => 'required|date'
        ]);

        if ($validator->fails()) {
            return null;
        }

        $userId = (int)$data['user_id'];
        
        // Ensure idempotency for creating schedules
        $explicitKey = $data['idempotency_key'] ?? null;

        return $this->idempotencyService->executeWithTransaction(
            'scheduled_payment.create',
            $userId,
            $data,
            function() use ($data) {
                return $this->scheduledPaymentModel->createSchedule($data);
            },
            $explicitKey
        );
    }

    public function processDuePayments(int $limit = 50): array
    {
        $processed = 0;
        $failed = 0;
        $details = [];

        return $this->getTransactionWrapper()->runWithRetry(function($db) use ($limit, &$processed, &$failed, &$details) {
            $due = $this->scheduledPaymentModel->getDuePayments($limit);

            foreach ($due as $payment) {
                try {
                    $this->getTransactionWrapper()->runWithRetry(function($db) use ($payment, &$processed, &$failed, &$details) {
                        if ($this->walletService->isWalletFrozen((int)$payment->user_id)) {
                            $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'paused');
                            $details[] = ['id' => $payment->id, 'status' => 'paused', 'reason' => 'wallet_frozen'];
                            $failed++;
                            return;
                        }

                        // 🔒 Lock the wallet row to serialize concurrent balance checks and withdrawals (prevent TOCTOU)
                        $db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [(int)$payment->user_id])->fetch();

                        if (!$this->walletService->hasBalance((int)$payment->user_id, (string)$payment->amount, $payment->currency)) {
                            $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');
                            $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => 'insufficient_funds'];
                            $failed++;
                            return;
                        }

                        if ($this->escrowService) {
                            $txId = $this->escrowService->holdFunds(
                                (int)$payment->id,
                                'scheduled_payment',
                                (int)$payment->user_id,
                                -1,
                                (string)$payment->amount,
                                $payment->currency
                            );
                            if (empty($txId) || empty($txId['ok'])) {
                                throw new \RuntimeException('Failed to hold funds in escrow: ' . ($txId['error'] ?? 'Unknown error'));
                            }
                        } else {
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
                        }

                        $nextRun = $this->calculateNextRun((string)$payment->frequency, (string)$payment->next_run_at);
                        $status = $payment->frequency === 'one_time' ? 'completed' : 'active';
                        $this->scheduledPaymentModel->updateNextRun((int)$payment->id, $nextRun, $status);

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
                    });
                } catch (\Exception $e) {
                    $this->scheduledPaymentModel->updateStatus((int)$payment->id, 'failed');
                    $details[] = ['id' => $payment->id, 'status' => 'failed', 'reason' => $e->getMessage()];
                    $failed++;
                    $this->logger->error('scheduled_payment.process.failed', [
                        'payment_id' => $payment->id,
                        'user_id' => $payment->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            return ['processed' => $processed, 'failed' => $failed, 'details' => $details];
        });

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

