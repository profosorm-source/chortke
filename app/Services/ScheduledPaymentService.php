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
    private ScheduledPayment $scheduledPaymentModel;
    private WalletService $walletService;
    private LedgerService $ledgerService;
    private Wallet $walletModel;
    private Transaction $transactionModel;
    private Database $db;

    public function __construct(
        ScheduledPayment $scheduledPaymentModel,
        WalletService $walletService,
        LedgerService $ledgerService,
        Wallet $walletModel,
        Transaction $transactionModel,
        Database $db,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->scheduledPaymentModel = $scheduledPaymentModel;
        $this->walletService = $walletService;
        $this->ledgerService = $ledgerService;
        $this->walletModel = $walletModel;
        $this->transactionModel = $transactionModel;
        $this->db = $db;
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

                if (!$this->walletService->getOrCreateWallet((int)$payment->user_id)) {
                    throw new \RuntimeException('Unable to load wallet');
                }

                $balanceBefore = $this->walletService->getBalance((int)$payment->user_id, $payment->currency);
                $balanceAfter = (float)bcsub((string)$balanceBefore, (string)$payment->amount, 4);

                if (!$this->walletModel->updateBalance((int)$payment->user_id, -$payment->amount, $payment->currency)) {
                    throw new \RuntimeException('Unable to debit wallet');
                }

                $transaction = $this->transactionModel->create([
                    'user_id' => (int)$payment->user_id,
                    'type' => 'scheduled_payment',
                    'currency' => $payment->currency,
                    'amount' => -$payment->amount,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'status' => 'completed',
                    'description' => $payment->description ?? 'Scheduled payment charge',
                    'metadata' => json_encode([ 'scheduled_payment_id' => $payment->id ]),
                ]);

                if (!$transaction) {
                    throw new \RuntimeException('Unable to create scheduled payment transaction');
                }

                $this->ledgerService->recordDoubleEntry(
                    $transaction->transaction_id,
                    "wallet:{$payment->user_id}",
                    'scheduled_payment',
                    $payment->amount,
                    'Scheduled payment charge',
                    ['scheduled_payment_id' => $payment->id]
                );

                $nextRun = $this->calculateNextRun((string)$payment->frequency, (string)$payment->next_run_at);
                $status = $payment->frequency === 'one_time' ? 'completed' : 'active';
                $this->scheduledPaymentModel->updateNextRun((int)$payment->id, $nextRun, $status);

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
