<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Core\Database;
use Core\EventDispatcher;
use App\Exceptions\BusinessException;
use App\Validators\Requests\RateCustomTaskRequest;
use App\Services\StateMachineService;
use App\Events\TaskCompletedEvent;

/**
 * CustomTaskModerationService - Handles advertiser moderation workflows (approving/rejecting submissions, rating workers, paying rewards)
 */
class CustomTaskModerationService
{

    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private AppSettings $appSettings;
    private StateMachineService $stateMachine;
    private ?\App\Services\OutboxService $outbox;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        AppSettings $appSettings,
        ?StateMachineService $stateMachine = null,
        ?\App\Services\OutboxService $outbox = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;

        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->appSettings = $appSettings;
        $this->outbox = $outbox;
        $this->stateMachine = $stateMachine ?? new StateMachineService($this->logger, $this->db);
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

            $sub = $this->submissionModel->submission_findByIdForUpdate($submission->id);
            
            if (!$sub) {
                throw new \Exception('درخواست یافت نشد.');
            }

            if ($sub->status === 'approved') {
                throw new \Exception('این درخواست قبلاً تایید شده است.');
            }

            if (!$this->stateMachine->canTransition('custom_task_submission', $sub->status, 'approved')) {
                throw new \Exception("تغییر وضعیت از وضعیت فعلی ({$sub->status}) به approved مجاز نیست.");
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

            $sub = $this->submissionModel->submission_findByIdForUpdate($submission->id);
            
            if (!$sub) {
                throw new \Exception('درخواست یافت نشد.');
            }

            if ($sub->status === 'rejected') {
                throw new \Exception('این درخواست قبلاً رد شده است.');
            }

            if (!$this->stateMachine->canTransition('custom_task_submission', $sub->status, 'rejected')) {
                throw new \Exception("تغییر وضعیت از وضعیت فعلی ({$sub->status}) به rejected مجاز نیست.");
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\CustomTask\PayRewardJob::class);
        $job->handle($submission);
    }

    public function rateSubmission(int $submissionId, int $raterId, array $ratingData): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\CustomTask\RateSubmissionJob::class);
        return $job->handle($submissionId, $raterId, $ratingData);
    }
}
