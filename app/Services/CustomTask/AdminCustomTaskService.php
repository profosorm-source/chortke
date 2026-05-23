<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Services\BaseService;
use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Services\WalletService;
use App\Services\Notification\NotificationService;
use App\Services\SettingService;
use Core\Database;
use Core\Logger;
use App\Services\StateMachineService;

/**
 * AdminCustomTaskService - Handles admin-level features and scheduled background actions (Cron)
 */
class AdminCustomTaskService extends BaseService
{
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private Database $db;
    private WalletService $walletService;
    private NotificationService $notificationService;
    private CustomTaskModerationService $moderationService;
    private SettingService $settingService;
    private StateMachineService $stateMachine;

    public function __construct(
        Logger $logger,
        Database $db,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        WalletService $walletService,
        NotificationService $notificationService,
        CustomTaskModerationService $moderationService,
        SettingService $settingService,
        StateMachineService $stateMachine
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->moderationService = $moderationService;
        $this->settingService = $settingService;
        $this->stateMachine = $stateMachine;
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

            $this->notificationService->send(
                $task->user_id,
                'task_approved',
                'وظیفه شما تایید شد',
                "وظیفه «{$task->title}» توسط مدیریت تایید و فعال شد.",
                ['task_id' => $taskId]
            );

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
                        $txId = $this->walletService->deposit($task->user_id, $refundAmount, $currency, [
                            'type' => 'escrow_refund',
                            'description' => "برگشت بودجه وظیفه #{$taskId} به دلیل رد توسط مدیریت. علت: {$reason}",
                            'idempotency_key' => $idempotencyKey
                        ]);

                        if (!$txId) {
                            throw new \RuntimeException('خطا در بازگشت بودجه به کیف پول.');
                        }
                    }

                    // inside transaction, we also reset remaining_budget
                    $this->db->query("UPDATE ads SET remaining_budget = 0 WHERE id = ?", [$taskId]);
                    return null;
                }
            );

            if (!$transitionResult['success']) {
                return ['success' => false, 'message' => $transitionResult['message']];
            }

            $this->notificationService->send(
                $task->user_id,
                'task_rejected',
                'وظیفه شما رد شد',
                "وظیفه «{$task->title}» توسط مدیریت رد شد. علت: {$reason}",
                ['task_id' => $taskId, 'reason' => $reason]
            );

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
                $txId = $this->walletService->deposit($task->user_id, $refundAmount, $currency, [
                    'type' => 'escrow_refund',
                    'description' => "برگشت بودجه وظیفه #{$taskId} به دلیل حذف توسط مدیریت.",
                    'idempotency_key' => $idempotencyKey
                ]);

                if (!$txId) {
                    $this->db->rollBack();
                    return ['ok' => false, 'message' => 'خطا در بازگشت بودجه به کیف پول.'];
                }
            }

            $this->db->query("UPDATE ads SET deleted_at = NOW(), remaining_budget = 0, status = 'completed' WHERE id = ?", [$taskId]);
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
        $hours = (int) $this->settingService->get('custom_task_auto_approve_hours', 48);
        $submissions = $this->submissionModel->getOldSubmissionsForAutoApproval($hours);

        $approved = 0;
        foreach ($submissions as $sub) {
            $result = $this->moderationService->approveSubmission($sub);
            if ($result['success']) {
                $approved++;

                $this->notificationService->send(
                    $sub->worker_id,
                    'auto_approved',
                    'مدرک شما به صورت خودکار تایید شد',
                    "مدرک شما برای وظیفه «{$sub->task_title}» به دلیل عدم بررسی توسط تبلیغ‌کننده، خودکار تایید و پاداش پرداخت شد.",
                    [
                        'submission_id' => $sub->id,
                        'task_id' => $sub->task_id
                    ]
                );
            }
        }

        return $approved;
    }

    public function expireOldSubmissions(): int
    {
        $expired = 0;
        $submissions = $this->submissionModel->submission_getExpiredSubmissions();

        foreach ($submissions as $sub) {
            try {
                $this->db->beginTransaction();

                $this->submissionModel->submission_update($sub->id, [
                    'status' => 'expired',
                ]);

                $this->taskModel->decrementPendingCount($sub->task_id);

                $this->db->commit();
                $expired++;

            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->logger->error('expire_submission_failed', [
                    'submission_id' => $sub->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $expired;
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
                    
                    $this->walletService->deposit($userId, $refundAmount, $currency, [
                        'type' => 'escrow_refund',
                        'description' => "استرداد بودجه تبلیغ #{$adArr['id']} به دلیل لغو حساب کاربری",
                        'idempotency_key' => $idempotencyKey
                    ]);

                    $this->db->query("UPDATE ads SET remaining_budget = 0, status = 'completed', updated_at = NOW() WHERE id = ?", [$adArr['id']]);
                    
                    $this->logger->info('escrow.refunded_during_deletion', [
                        'ad_id' => $adArr['id'],
                        'user_id' => $userId,
                        'refund' => $refundAmount,
                        'currency' => $currency
                    ]);
                }
            }

            $this->db->query("UPDATE custom_tasks SET status = 'cancelled', updated_at = NOW() WHERE user_id = ? AND status NOT IN ('completed', 'cancelled')", [$userId]);
            
        } catch (\Throwable $e) {
            $this->logger->error('escrow.bulk_refund_during_deletion_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
