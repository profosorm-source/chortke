<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Models\CustomTaskAnalyticsModel;
use App\Services\Settings\AppSettings;
use App\Services\Notification\NotificationService;
use App\Services\AntiFraud\FraudGuardService;

use Core\Database;
use Core\Logger;
use App\Exceptions\BusinessException;
use App\Validators\Requests\SubmitCustomTaskProofRequest;

/**
 * CustomTaskExecutorService - Handles worker/executor task workflows
 */
class CustomTaskExecutorService
{

    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private CustomTaskAnalyticsModel $analyticsModel;
    private AppSettings $appSettings;
    private \Core\RateLimiter $rateLimiter;
    private NotificationService $notificationService;
    private FraudGuardService $fraudGuard;
    private ?\App\Services\DistributedLockService $lockService;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        CustomTaskAnalyticsModel $analyticsModel,
        AppSettings $appSettings,
        \Core\RateLimiter $rateLimiter,
        NotificationService $notificationService,
        FraudGuardService $fraudGuard,
        ?\App\Services\DistributedLockService $lockService = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;

        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->analyticsModel = $analyticsModel;
        $this->appSettings = $appSettings;
        $this->rateLimiter = $rateLimiter;
        $this->notificationService = $notificationService;
        $this->fraudGuard = $fraudGuard;
        $this->lockService = $lockService ?? \Core\Container::getInstance()->make(\App\Services\DistributedLockService::class);
    }



    public function startTask(int $taskId, int $workerId): array
    {
        $risk = $this->fraudGuard->checkAction($workerId, 'task.custom', [
            'task_id'    => $taskId,
            'ip'         => $this->clientIp(),
            'user_agent' => $this->userAgent(),
            'session_id' => session_id() ?: ''
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('task.custom_start_blocked_by_fraud_guard', [
                'worker_id' => $workerId,
                'task_id'   => $taskId,
                'reason'    => $risk['reason']
            ]);
            return ['success' => false, 'message' => 'امکان شروع تسک به دلیل رفتارهای نامتعارف سیستمی مسدود شد. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از سقف فعالیت مجاز روزانه' : 'تشخیص فعالیت غیرمجاز')];
        }

        return $this->lockService->synchronized("custom_task_start_{$workerId}", function() use ($taskId, $workerId) {
            $this->db->beginTransaction();

        if (!$this->rateLimiter->attempt('custom_task:start:' . $workerId, 15, 5)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => "تعداد تلاش‌های شما برای شروع تسک بیش از حد مجاز است."];
        }

        $task = $this->taskModel->findByIdForUpdate($taskId);

        if (!$task || $task->status !== 'active') {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'وظیفه فعال نیست.'];
        }

        if ($task->user_id === $workerId) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'نمی‌توانید وظیفه خودتان را انجام دهید.'];
        }

        if ($this->submissionModel->submission_hasWorkerDone($taskId, $workerId)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'شما قبلاً این وظیفه را شروع کرده اید.'];
        }

        $maxDaily = (int) $this->appSettings->get('custom_task_max_daily_submissions', 20);
        if ($this->submissionModel->submission_todayCount($workerId) >= $maxDaily) {
            $this->db->rollBack();
            return ['success' => false, 'message' => "سقف انجام تسک روزانه ({$maxDaily}) تکمیل شده."];
        }

        $remaining = (int)$task->total_count - (int)$task->completed_count - (int)$task->pending_count;
        if ($remaining <= 0) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'ظرفیت وظیفه تکمیل شده است.'];
        }

        try {
            $deadline = date('Y-m-d H:i:s', time() + (($task->deadline_hours ?? 24) * 3600));
            
            $subId = $this->submissionModel->submission_create([
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'reward_amount' => $task->price_per_task,
                'reward_currency' => $task->currency,
                'deadline_at' => $deadline,
                'status' => 'in_progress',
                'worker_ip' => $this->clientIp(),
                'worker_fingerprint' => md5($this->userAgent() ?: 'unknown')
            ]);

            $this->taskModel->incrementPendingCount($taskId);

            $this->db->commit();
            return ['success' => true, 'submission_id' => $subId, 'deadline' => $deadline];
        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در تخصیص وظیفه.'];
        }
        });
    }

    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\CustomTask\SubmitProofJob::class);
        return $job->handle(['submission_id' => $submissionId, 'worker_id' => $workerId, 'proof_data' => $proofData]);
    }

    public function recordTaskView(int $taskId, int $userId): void
    {
        $this->analyticsModel->recordTaskView($taskId);
    }

    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
}
