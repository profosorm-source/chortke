<?php

declare(strict_types=1);

namespace App\Jobs\CustomTask;

use App\Models\CustomTaskSubmissionModel;
use App\Models\User;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\ReferralService;
use App\Services\Shared\IdempotencyService;
use Core\Logger;
use App\Services\OutboxService;

class PayRewardJob
{
    private CustomTaskSubmissionModel $submissionModel;
    private User $userModel;
    private WalletServiceInterface $walletService;
    private ReferralService $referralService;
    private Logger $logger;
    private ?OutboxService $outbox;
    private IdempotencyService $idempotencyService;
    public function __construct(
        CustomTaskSubmissionModel $submissionModel,
        User $userModel,
        WalletServiceInterface $walletService,
        ReferralService $referralService,
        Logger $logger,
        ?OutboxService $outbox = null,
        IdempotencyService $idempotencyService
    ) {        $this->submissionModel = $submissionModel;
        $this->userModel = $userModel;
        $this->walletService = $walletService;
        $this->referralService = $referralService;
        $this->logger = $logger;
        $this->outbox = $outbox;
        $this->idempotencyService = $idempotencyService;
}

    public function handle(object $submission): void
    {
        $idempotencyKey = "ctask_reward_{$submission->id}";
        // Use transactional outbox to enqueue async wallet deposit so it's durable with DB transaction
        try {
            $payload = [
                'user_id' => $submission->worker_id,
                'amount' => $submission->reward_amount,
                'currency' => $submission->reward_currency,
                'metadata' => [
                    'type' => 'task_reward',
                    'description' => "پاداش وظیفه #{$submission->task_id}",
                    'idempotency_key' => $idempotencyKey,
                    'submission_id' => $submission->id,
                ],
            ];

            if ($this->outbox) {
                $ok = $this->outbox->record('custom_task_submission', (int)$submission->id, 'wallet.deposit.requested', $payload);
                if ($ok) {
                    $this->submissionModel->submission_update($submission->id, [
                        'reward_paid' => 1,
                        'reward_transaction_id' => null,
                    ]);
                } else {
                    $this->logger->error('custom_task.outbox_record_failed', ['submission_id' => $submission->id]);
                }
            } else {
                // Fallback: synchronous deposit via IdempotencyService
                $payload['metadata']['idempotency_key'] = $idempotencyKey;
                $txId = $this->idempotencyService->executeWithTransaction(
                    'wallet.deposit',
                    $submission->worker_id,
                    $payload,
                    function () use ($submission, $payload) {
                        return $this->walletService->deposit($submission->worker_id, $submission->reward_amount, $submission->reward_currency, $payload['metadata']);
                    },
                    $idempotencyKey
                );

                if (isset($txId['success']) && $txId['success']) {
                    $this->submissionModel->submission_update($submission->id, [
                        'reward_paid' => 1,
                        'reward_transaction_id' => $txId['transaction_id'],
                    ]);
                }
            }

            $userRecord = $this->userModel->findById($submission->worker_id);
            if ($userRecord && !empty($userRecord->referred_by)) {
                // Migrated to event-driven referral commission (unchanged)
                $this->eventDispatcher?->dispatch('referral.commission.process', [
                    'referrer_id' => (int)$userRecord->referred_by,
                    'amount' => (float)$submission->reward_amount,
                    'currency' => $submission->reward_currency,
                    'source_user_id' => $submission->worker_id,
                    'context' => [
                        'action' => 'custom_task_reward',
                        'executor_id' => $submission->worker_id,
                        'execution_id' => $submission->id
                    ]
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('custom_task.pay_worker_outbox_failed', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);
        }
    }
}
