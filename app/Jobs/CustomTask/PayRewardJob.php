<?php

declare(strict_types=1);

namespace App\Jobs\CustomTask;

use App\Models\CustomTaskSubmissionModel;
use App\Models\User;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\ReferralService;
use Core\Logger;
use App\Services\OutboxService;

class PayRewardJob
{
    public function __construct(
        private CustomTaskSubmissionModel $submissionModel,
        private User $userModel,
        private WalletServiceInterface $walletService,
        private ReferralService $referralService,
        private Logger $logger,
        private ?OutboxService $outbox = null
    ) {}

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
                // Fallback: synchronous deposit
                $txId = $this->walletService->deposit(
                    $submission->worker_id,
                    $submission->reward_amount,
                    $submission->reward_currency,
                    [
                        'type' => 'task_reward',
                        'description' => "پاداش وظیفه #{$submission->task_id}",
                        'idempotency_key' => $idempotencyKey,
                    ]
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
