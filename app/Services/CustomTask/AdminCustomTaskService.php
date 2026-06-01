<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Contracts\WalletServiceInterface;
use App\Services\Notification\NotificationService;
use App\Services\Settings\AppSettings;
use Core\Database;
use App\Services\StateMachineService;
use App\Events\TaskCompletedEvent;

/**
 * AdminCustomTaskService - Handles admin-level features and scheduled background actions (Cron)
 */
class AdminCustomTaskService
{
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private WalletServiceInterface $walletService;
    private NotificationService $notificationService;
    private CustomTaskModerationService $moderationService;
    private AppSettings $appSettings;
    private StateMachineService $stateMachine;
    private \App\Contracts\LoggerInterface $logger;

    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        WalletServiceInterface $walletService,
        NotificationService $notificationService,
        CustomTaskModerationService $moderationService,
        AppSettings $appSettings,
        StateMachineService $stateMachine,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;

        $this->logger = $logger;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->moderationService = $moderationService;
        $this->appSettings = $appSettings;
        $this->stateMachine = $stateMachine;
        $this->outboxService = $outboxService;
    }

    public function getTaskDetailsForAdmin(int $taskId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name as user_name, u.email as user_email
            FROM ads a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.id = ? AND a.type = 'custom_task'
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$task) {
            return null;
        }

        $submissions = $this->submissionModel->submission_getByTask($taskId);

        return [
            'task' => $task,
            'submissions' => $submissions
        ];
    }

    public function approveTask(int $taskId, int $adminId): array
    {
        try {
            $task = $this->taskModel->find($taskId);
            if (!$task || $task->type !== 'custom_task') {
                return ['success' => false, 'message' => 'تسک یافت نشد.'];
            }

            $transitionResult = $this->stateMachine->executeTransition(
                'custom_task',
                'ads',
                $taskId,
                'active',
                function($currentStatus) {
                    return null;
                }
            );

            if (!$transitionResult['success']) {
                return ['success' => false, 'message' => $transitionResult['message']];
            }

            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $task->user_id,
                'type' => 'task_approved',
                'title' => 'وظیفه شما تایید شد',
                'message' => "وظیفه «{$task->title}» توسط مدیریت تایید و فعال شد.",
                'data' => ['task_id' => $taskId]
            ]);

            return ['success' => true, 'message' => 'وظیفه با موفقیت تایید شد.'];
        } catch (\Exception $e) {
            $this->logger->error('task.approve.failed', ['task_id' => $taskId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در تایید وظیفه.'];
        }
    }

    public function rejectTask(int $taskId, int $adminId, ?string $reason): array
    {
        try {
            $task = $this->taskModel->find($taskId);
            if (!$task || $task->type !== 'custom_task') {
                return ['success' => false, 'message' => 'تسک یافت نشد.'];
            }

            $transitionResult = $this->stateMachine->executeTransition(
                'custom_task',
                'ads',
                $taskId,
                'rejected',
                function($currentStatus) use ($task, $taskId, $reason) {
                    $remaining = (float)$task->remaining_budget;
                    if ($remaining > 0) {
                        $feePercent = (float)($task->site_commission_percent ?? 10);
                        $refundAmount = round($remaining * (1 + ($feePercent / 100)), 2);
                        $currency = $task->currency ?? 'irt';

                        $idempotencyKey = "task_reject_refund_{$taskId}";
                        $payload = [
                            'user_id' => (int)$task->user_id,
                            'amount' => $refundAmount,
                            'currency' => $currency,
                            'metadata' => [
                                'type' => 'escrow_refund',
                                'description' => "برگشت بودجه وظیفه #{$taskId} به دلیل رد توسط مدیریت. علت: {$reason}",
                                'idempotency_key' => $idempotencyKey,
                                'task_id' => $taskId,
                            ],
                        ];

                        if (isset($this->outboxService) && $this->outboxService) {
                            $ok = $this->outboxService->record('custom_task', $taskId, \App\Events\Registry\EventRegistry::CUSTOM_TASK_REFUNDED, $payload);
                            if (!$ok) {
                                throw new \RuntimeException('خطا در بازگشت بودجه به کیف پول.');
                            }
                        } else {
                            $txId = $this->walletService->deposit($task->user_id, $refundAmount, $currency, [
                                'type' => 'escrow_refund',
                                'description' => "برگشت بودجه وظیفه #{$taskId} به دلیل رد توسط مدیریت. علت: {$reason}",
                                'idempotency_key' => $idempotencyKey
                            ]);

                            if (!$txId) {
                                throw new \RuntimeException('خطا در بازگشت بودجه به کیف پول.');
                            }
                        }
                    }

                    // inside transaction, we also reset remaining_budget
                    $this->adModel->cancelAdRemainingBudget($taskId);
                    return null;
                }
            );

            if (!$transitionResult['success']) {
                return ['success' => false, 'message' => $transitionResult['message']];
            }

            $this->eventDispatcher->dispatchAsync('notification.requested', [
                'user_id' => $task->user_id,
                'type' => 'task_rejected',
                'title' => 'وظیفه شما رد شد',
                'message' => "وظیفه «{$task->title}» توسط مدیریت رد شد. علت: {$reason}",
                'data' => ['task_id' => $taskId, 'reason' => $reason]
            ]);

            return ['success' => true, 'message' => 'وظیفه رد و بودجه با موفقیت مسترد شد.'];
        } catch (\Exception $e) {
            $this->logger->error('task.reject.failed', ['task_id' => $taskId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در رد وظیفه.'];
        }
    }

    public function pauseTask(int $taskId, int $adminId): array
    {
        try {
            $task = $this->taskModel->find($taskId);
            if (!$task || $task->type !== 'custom_task') {
                return ['ok' => false, 'message' => 'تسک یافت نشد.'];
            }

            $transitionResult = $this->stateMachine->executeTransition(
                'custom_task',
                'ads',
                $taskId,
                'paused',
                function($currentStatus) {
                    return null;
                }
            );

            if (!$transitionResult['success']) {
                return ['ok' => false, 'message' => $transitionResult['message']];
            }

            return ['ok' => true, 'message' => 'تسک با موفقیت متوقف شد.'];
        } catch (\Exception $e) {
            $this->logger->error('task.pause.failed', ['task_id' => $taskId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'خطا در توقف تسک.'];
        }
    }

    public function deleteTask(int $taskId, int $adminId): array
    {
        try {
            $task = $this->taskModel->find($taskId);
            if (!$task || $task->type !== 'custom_task') {
                return ['ok' => false, 'message' => 'تسک یافت نشد.'];
            }

            $this->db->beginTransaction();

            $remaining = (float)$task->remaining_budget;
            if ($remaining > 0 && $task->status !== 'completed' && $task->status !== 'rejected') {
                $feePercent = (float)($task->site_commission_percent ?? 10);
                $refundAmount = round($remaining * (1 + ($feePercent / 100)), 2);
                $currency = $task->currency ?? 'irt';

                $idempotencyKey = "task_delete_refund_{$taskId}";
                $payload = [
                    'user_id' => (int)$task->user_id,
                    'amount' => $refundAmount,
                    'currency' => $currency,
                    'metadata' => [
                        'type' => 'escrow_refund',
                        'description' => "برگشت بودجه وظیفه #{$taskId} به دلیل حذف توسط مدیریت.",
                        'idempotency_key' => $idempotencyKey,
                        'task_id' => $taskId,
                    ],
                ];

                if (isset($this->outboxService) && $this->outboxService) {
                    $ok = $this->outboxService->record('custom_task', $taskId, \App\Events\Registry\EventRegistry::CUSTOM_TASK_REFUNDED, $payload);
                    if (!$ok) {
                        throw new \Exception('خطا در بازگشت بودجه به کیف پول.');
                    }
                } else {
                    $txId = $this->walletService->deposit($task->user_id, $refundAmount, $currency, [
                        'type' => 'escrow_refund',
                        'description' => "برگشت بودجه وظیفه #{$taskId} به دلیل حذف توسط مدیریت.",
                        'idempotency_key' => $idempotencyKey
                    ]);

                    if (!$txId) {
                        throw new \Exception('خطا در بازگشت بودجه به کیف پول.');
                    }
                }
            }

            $this->adModel->completeAdAndClearBudget($taskId, true);

            $this->db->commit();

            return ['ok' => true, 'message' => 'تسک با موفقیت حذف شد.'];
        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('task.delete.failed', ['task_id' => $taskId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'message' => 'خطا در حذف تسک.'];
        }
    }

    public function forceApproveSubmissionByAdmin(int $submissionId, int $adminId): array
    {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['ok' => false, 'message' => 'یافت نشد.'];
        }

        if (!in_array($submission->status, ['submitted', 'disputed', 'rejected'])) {
            return ['ok' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        try {
            $this->db->beginTransaction();

            $this->submissionModel->submission_update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            $this->moderationService->payWorkerReward($submission);

            $pendingDecrease = in_array($submission->status, ['submitted']) ? true : false;
            $this->taskModel->incrementCustomTaskCompletion($submission->task_id, (float)$submission->reward_amount, $pendingDecrease);

            $this->db->commit();

            $this->logger->info('Submission force approved by admin', [
                'submission_id' => $submission->id,
                'admin_id' => $adminId,
            ]);

            // Dispatch typed event for downstream consumers
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

            return ['ok' => true, 'message' => 'درخواست توسط ادمین تایید شد.'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('task.force_approval.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'خطا در تایید.'];
        }
    }

    public function forceRejectSubmissionByAdmin(int $submissionId, int $adminId, ?string $reason = null): array
    {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['ok' => false, 'message' => 'یافت نشد.'];
        }

        if (!in_array($submission->status, ['submitted', 'disputed', 'approved'])) {
            return ['ok' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        try {
            $this->db->beginTransaction();

            $this->submissionModel->submission_update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason ?? 'رد شده توسط مدیریت',
            ]);

            $pendingDecrease = in_array($submission->status, ['submitted']) ? 1 : 0;
            if ($pendingDecrease) {
                $this->taskModel->decrementPendingCount($submission->task_id);
            }

            $this->db->commit();

            $this->logger->info('Submission force rejected by admin', [
                'submission_id' => $submission->id,
                'admin_id' => $adminId,
                'reason' => $reason,
            ]);

            return ['ok' => true, 'message' => 'درخواست توسط ادمین رد شد.'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('task.force_rejection.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => 'خطا در رد درخواست.'];
        }
    }

    public function getAdminStats(): array
    {
        $taskStats = $this->db->fetchAll("
            SELECT status, COUNT(*) as count 
            FROM ads 
            WHERE type = 'custom_task' AND deleted_at IS NULL 
            GROUP BY status
        ");

        $submissionStats = $this->db->fetchAll("
            SELECT status, COUNT(*) as count 
            FROM custom_task_submissions 
            GROUP BY status
        ");

        return [
            'tasks' => $taskStats,
            'submissions' => $submissionStats
        ];
    }

    public function getAdminAnalytics(): array
    {
        $taskStats = $this->db->fetchAll("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'pending_review' OR status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(total_budget) as total_budget,
                AVG(price_per_task) as avg_reward
            FROM ads 
            WHERE type = 'custom_task' AND deleted_at IS NULL
        ");

        $submissionStats = $this->db->fetchAll("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as pending
            FROM custom_task_submissions
        ");

        return [
            'taskStats' => $taskStats[0] ?? ['total' => 0, 'active' => 0, 'pending' => 0, 'total_budget' => 0, 'avg_reward' => 0],
            'submissionStats' => $submissionStats[0] ?? ['total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0],
        ];
    }

    public function autoApproveOldSubmissions(): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\CustomTask\CronSubmissionsJob::class);
        return $job->autoApproveOldSubmissions();
    }

    public function expireOldSubmissions(): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\CustomTask\CronSubmissionsJob::class);
        return $job->expireOldSubmissions();
    }

    public function cancelActiveTasksForUser(int $userId): void
    {
        try {
            $activeAds = $this->db->fetchAll(
                "SELECT id, title, type, currency, total_budget, remaining_budget, site_commission_percent 
                 FROM ads 
                 WHERE user_id = ? AND status IN ('active', 'pending', 'paused', 'draft', 'pending_review')",
                [$userId]
            );

            if (is_array($activeAds)) {
                foreach ($activeAds as $ad) {
                    $adArr = (array)$ad;
                    $remaining = (float)($adArr['remaining_budget'] ?? 0);
                    if ($remaining <= 0) {
                        continue;
                    }

                    $feePercent = (float)($adArr['site_commission_percent'] ?? 0);
                    $refundAmount = round($remaining * (1 + ($feePercent / 100)), 2);
                    $currency = $adArr['currency'] ?? 'irt';

                    $idempotencyKey = "escrow_rfnd_ad_" . ($adArr['id'] ?? 0) . "_del";
                    // Update DB first to mark ad as refunded/completed, then perform async wallet deposit
                    $this->adModel->completeAdAndClearBudget((int)$adArr['id'], false);

                    try {
                        if (isset($this->outboxService) && $this->outboxService) {
                            $this->outboxService->record('custom_task', $adArr['id'], \App\Events\Registry\EventRegistry::CUSTOM_TASK_REFUNDED, [
                                'user_id' => $userId,
                                'amount' => $refundAmount,
                                'currency' => $currency,
                                'metadata' => [
                                    'type' => 'escrow_refund',
                                    'description' => "استرداد بودجه تبلیغ #{$adArr['id']} به دلیل لغو حساب کاربری",
                                    'idempotency_key' => $idempotencyKey,
                                    'ad_id' => $adArr['id']
                                ]
                            ]);
                        } else {
                            $this->eventDispatcher?->dispatchAsync(\App\Events\Registry\EventRegistry::CUSTOM_TASK_REFUNDED, [
                                'user_id' => $userId,
                                'amount' => $refundAmount,
                                'currency' => $currency,
                                'metadata' => [
                                    'type' => 'escrow_refund',
                                    'description' => "استرداد بودجه تبلیغ #{$adArr['id']} به دلیل لغو حساب کاربری",
                                    'idempotency_key' => $idempotencyKey,
                                    'ad_id' => $adArr['id']
                                ]
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('escrow.refund.dispatch_async_failed', [
                            'ad_id' => $adArr['id'] ?? null,
                            'user_id' => $userId,
                            'error' => $e->getMessage()
                        ]);
                    }

                    $this->logger->info('escrow.refunded_during_deletion', [
                        'ad_id' => $adArr['id'],
                        'user_id' => $userId,
                        'refund' => $refundAmount,
                        'currency' => $currency
                    ]);
                }
            }

            $this->adModel->cancelUserCustomTasks($userId);
            
        } catch (\Throwable $e) {
            $this->logger->error('escrow.bulk_refund_during_deletion_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
