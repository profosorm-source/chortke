<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Services\BaseService;
use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Models\User;
use App\Services\Wallet\WalletService;
use App\Services\Shared\ReferralService;
use App\Services\Notification\NotificationService;
use App\Services\SettingService;
use App\Traits\ValidationTrait;
use Core\Database;
use Core\Logger;
use Core\EventDispatcher;
use App\Exceptions\BusinessException;
use App\Validators\Requests\RateCustomTaskRequest;
use App\Services\StateMachineService;
use App\Events\TaskCompletedEvent;

/**
 * CustomTaskModerationService - Handles advertiser moderation workflows (approving/rejecting submissions, rating workers, paying rewards)
 */
class CustomTaskModerationService extends BaseService
{
    use ValidationTrait;
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private \App\Services\Interaction\RatingService $ratingService;
    private User $userModel;
    private WalletService $walletService;
    private ReferralService $referralService;
    private NotificationService $notificationService;
    private SettingService $settingService;
    private StateMachineService $stateMachine;

    public function __construct(
        Logger $logger,
        Database $db,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        \App\Services\Interaction\RatingService $ratingService,
        User $userModel,
        WalletService $walletService,
        ReferralService $referralService,
        NotificationService $notificationService,
        SettingService $settingService,
        ?StateMachineService $stateMachine = null,
        ?EventDispatcher $eventDispatcher = null,
        ?\App\Services\OutboxService $outbox = null
    ) {
        parent::__construct($logger, null, null, null, null, null, null, $eventDispatcher);
        $this->db = $db;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->ratingService = $ratingService;
        $this->userModel = $userModel;
        $this->walletService = $walletService;
        $this->referralService = $referralService;
        $this->notificationService = $notificationService;
        $this->settingService = $settingService;
        $this->outbox = $outbox;
        $this->stateMachine = $stateMachine ?? new StateMachineService($logger, $db);
    }

    public function guardCanRateSubmission(int $raterId, array $data): void
    {
        // Use centralized validation pipeline instead of inline checks
        try {
            $validated = $this->validateWith(
                $data,
                [
                    'execution_id'  => 'required|integer|min:1',
                    'rating'        => 'required|integer|min:1|max:5',
                    'comment'       => 'nullable|string|min:5|max:1000',
                    'idempotency_key' => 'required|string|min:10|max:128',
                ],
                null, // No special authorization check needed
                [
                    'rater_id' => [
                        'callback' => fn() => $raterId > 0,
                        'message' => 'شناسه ارزیاب نامعتبر است'
                    ],
                    'rating_valid' => [
                        'callback' => fn() => (int)($data['rating'] ?? 0) >= 1 && (int)($data['rating'] ?? 0) <= 5,
                        'message' => 'امتیاز باید بین ۱ تا ۵ باشد'
                    ]
                ]
            );

            $this->logger->info('custom_task.guard.rate.passed', [
                'rater_id' => $raterId,
                'execution_id' => $data['execution_id'] ?? 0,
                'rating' => $data['rating'] ?? 0
            ]);
        } catch (BusinessException $e) {
            throw $e;
        }
    }

    public function reviewSubmission(
        int $submissionId,
        int $reviewerId,
        string $decision,
        ?string $reason = null
    ): array {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->creator_id !== $reviewerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'submitted') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        if (!in_array($decision, ['approve', 'reject'])) {
            return ['success' => false, 'message' => 'تصمیم نامعتبر.'];
        }

        if ($decision === 'approve') {
            return $this->approveSubmission($submission);
        } else {
            return $this->rejectSubmission($submission, $reason);
        }
    }

    public function approveSubmission(object $submission): array
    {
        try {
            $this->db->beginTransaction();

            $sub = $this->db->query("SELECT * FROM custom_task_submissions WHERE id = ? FOR UPDATE", [$submission->id])->fetch(\PDO::FETCH_OBJ);
            
            if (!$sub) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد.'];
            }

            if ($sub->status === 'approved') {
                $this->db->rollBack();
                return ['success' => true, 'message' => 'این درخواست قبلاً تایید شده است.'];
            }

            if (!$this->stateMachine->canTransition('custom_task_submission', $sub->status, 'approved')) {
                $this->db->rollBack();
                return ['success' => false, 'message' => "تغییر وضعیت از وضعیت فعلی ({$sub->status}) به approved مجاز نیست."];
            }

            $this->submissionModel->submission_update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            $this->payWorkerReward($submission);

            $this->taskModel->incrementCustomTaskCompletion($submission->task_id, (float)$submission->reward_amount);

            $this->db->commit();

            $this->logger->info('Submission approved', [
                'submission_id' => $submission->id,
                'worker_id' => $submission->worker_id,
            ]);

            // legacy string dispatch removed: migrated to typed TaskCompletedEvent
            
                // Dispatch typed TaskCompletedEvent for downstream consumers (XP, trust)
                try {
                    \Core\EventDispatcher::getInstance()->dispatch(TaskCompletedEvent::class, new TaskCompletedEvent(
                        (int)$submission->worker_id,
                        (int)$submission->task_id,
                        (float)$submission->reward_amount,
                        'CUSTOM_TASK'
                    ));
                } catch (\Throwable $evtErr) {
                    $this->logger->warning('custom_task.taskcompleted.event_failed', [
                        'submission_id' => $submission->id,
                        'error' => $evtErr->getMessage()
                    ]);
                }

            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $submission->worker_id,
                'type' => 'task_submission_approved',
                'title' => 'مدرک شما تایید شد',
                'message' => "مدرک شما برای وظیفه «{$submission->task_title}» تایید شد و پاداش پرداخت گردید.",
                'data' => [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reward' => $submission->reward_amount,
                    'currency' => $submission->reward_currency,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            ]);

            return ['success' => true, 'message' => 'درخواست تایید شد.'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('task.approval.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در تایید: ' . $e->getMessage()];
        }
    }

    public function rejectSubmission(object $submission, ?string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $sub = $this->db->query("SELECT * FROM custom_task_submissions WHERE id = ? FOR UPDATE", [$submission->id])->fetch(\PDO::FETCH_OBJ);
            
            if (!$sub) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست یافت نشد.'];
            }

            if ($sub->status === 'rejected') {
                $this->db->rollBack();
                return ['success' => true, 'message' => 'این درخواست قبلاً رد شده است.'];
            }

            if (!$this->stateMachine->canTransition('custom_task_submission', $sub->status, 'rejected')) {
                $this->db->rollBack();
                return ['success' => false, 'message' => "تغییر وضعیت از وضعیت فعلی ({$sub->status}) به rejected مجاز نیست."];
            }

            $this->submissionModel->submission_update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason,
            ]);

            $this->taskModel->decrementPendingCount($submission->task_id);

            $this->db->commit();

            $this->logger->info('Submission rejected', [
                'submission_id' => $submission->id,
                'reason' => $reason,
            ]);

            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $submission->worker_id,
                'type' => 'task_submission_rejected',
                'title' => 'مدرک شما رد شد',
                'message' => "متأسفانه مدرک شما برای وظیفه «{$submission->task_title}» رد شد. دلیل: {$reason}",
                'data' => [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reason' => $reason,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            ]);

            return ['success' => true, 'message' => 'درخواست رد شد.'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('task.rejection.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در رد درخواست.'];
        }
    }

    public function payWorkerReward(object $submission): void
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

    public function rateSubmission(int $submissionId, int $raterId, array $ratingData): array
    {
        try {
            $this->guardCanRateSubmission($raterId, $ratingData);
        } catch (BusinessException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->settingService->get('custom_task_rating_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم امتیازدهی غیرفعال است.'];
        }

        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->status !== 'approved') {
            return ['success' => false, 'message' => 'فقط می‌توانید به submission های تایید شده امتیاز دهید.'];
        }

        $rating = (int) ($ratingData['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'امتیاز باید بین 1 تا 5 باشد.'];
        }

        $reviewText = trim($ratingData['review_text'] ?? '');
        $minLength = (int) $this->settingService->get('custom_task_min_rating_text_length', 20);
        
        if (!empty($reviewText) && mb_strlen($reviewText) < $minLength) {
            return ['success' => false, 'message' => "متن نظر باید حداقل {$minLength} کاراکتر باشد."];
        }

        $task = $this->taskModel->find($submission->task_id);
        
        if ($raterId == $task->user_id) {
            $ratingType = 'worker';
            $ratedUserId = $submission->worker_id;
        } elseif ($raterId == $submission->worker_id) {
            $ratingType = 'creator';
            $ratedUserId = $task->user_id;
        } else {
            return ['success' => false, 'message' => 'شما مجاز به امتیازدهی نیستید.'];
        }

        try {
            $this->db->beginTransaction();

            $success = $this->ratingService->rate(
                $raterId,
                'custom_task',
                $task->id,
                \App\Enums\ModuleContext::CUSTOM_TASKS,
                $rating
            );

            if (!$success) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز.'];
            }

            $this->taskModel->updateTaskRatingStats($task->id);

            $this->db->commit();

            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $ratedUserId,
                'type' => 'new_rating_received',
                'title' => 'امتیاز جدید دریافت کردید',
                'message' => "امتیاز {$rating} ستاره برای وظیفه «{$task->title}» دریافت کردید.",
                'data' => [
                    'task_id' => $task->id,
                    'rating' => $rating
                ]
            ]);

            return [
                'success' => true,
                'message' => 'امتیاز با موفقیت ثبت شد.',
                'rating' => $ratingObj
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('rating.create.failed', [
                'error' => $e->getMessage(),
                'submission_id' => $submissionId,
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت امتیاز.'];
        }
    }
}
