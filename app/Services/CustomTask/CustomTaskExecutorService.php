<?php

declare(strict_types=1);

namespace App\Services\CustomTask;

use App\Services\BaseService;
use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Models\CustomTaskAnalyticsModel;
use App\Services\SettingService;
use App\Services\Notification\NotificationService;
use App\Services\AntiFraud\FraudGuardService;
use App\Traits\ValidationTrait;
use Core\Database;
use Core\Logger;
use App\Exceptions\BusinessException;
use App\Validators\Requests\SubmitCustomTaskProofRequest;

/**
 * CustomTaskExecutorService - Handles worker/executor task workflows
 */
class CustomTaskExecutorService extends BaseService
{
    use ValidationTrait;
    private Ads $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private CustomTaskAnalyticsModel $analyticsModel;
    private Database $db;
    private SettingService $settingService;
    private \Core\RateLimiter $rateLimiter;
    private NotificationService $notificationService;
    private FraudGuardService $fraudGuard;

    public function __construct(
        Logger $logger,
        Database $db,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        CustomTaskAnalyticsModel $analyticsModel,
        SettingService $settingService,
        \Core\RateLimiter $rateLimiter,
        NotificationService $notificationService,
        FraudGuardService $fraudGuard
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->analyticsModel = $analyticsModel;
        $this->settingService = $settingService;
        $this->rateLimiter = $rateLimiter;
        $this->notificationService = $notificationService;
        $this->fraudGuard = $fraudGuard;
    }

    public function guardCanSubmitProof(int $workerId, array $data): void
    {
        // Use centralized validation pipeline instead of inline checks
        try {
            $validated = $this->validateWith(
                $data,
                [
                    'task_execution_id' => 'required|integer|min:1',
                    'proof_data'        => 'required|string|min:10|max:5000',
                    'proof_type'        => 'required|in:screenshot,text,video,code,file',
                    'file_path'         => 'nullable|string|max:500',
                    'idempotency_key'   => 'required|string|min:10|max:128',
                ],
                null, // No special authorization check needed
                [
                    'worker_id' => [
                        'callback' => fn() => $workerId > 0,
                        'message' => 'شناسه کارگر نامعتبر است'
                    ]
                ]
            );

            $this->logger->info('custom_task.guard.submit_proof.passed', [
                'worker_id' => $workerId,
                'submission_id' => $data['task_execution_id'] ?? 0
            ]);
        } catch (BusinessException $e) {
            throw $e;
        }
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

        $this->db->beginTransaction();
        
        if (!$this->rateLimiter->attempt('custom_task:start:' . $workerId, 15, 5)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => "تعداد تلاش‌های شما برای شروع تسک بیش از حد مجاز است."];
        }

        $task = $this->db->query("SELECT * FROM ads WHERE id = ? FOR UPDATE", [$taskId])->fetch(\PDO::FETCH_OBJ);

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

        $maxDaily = (int) $this->settingService->get('custom_task_max_daily_submissions', 20);
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
    }

    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        try {
            $this->guardCanSubmitProof($workerId, $proofData);
        } catch (BusinessException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->rateLimiter->attempt('custom_task:submit:' . $workerId, 10, 10)) {
            $wait = ceil($this->rateLimiter->availableIn('custom_task:submit:' . $workerId) / 60);
            return ['success' => false, 'message' => "تعداد تلاش‌های ارسال پاسخ شما فراتر از حد مجاز است. لطفاً {$wait} دقیقه دیگر امتحان کنید."];
        }

        try {
            $this->db->beginTransaction();

            $submission = $this->db->query("SELECT * FROM custom_task_submissions WHERE id = ? FOR UPDATE", [$submissionId])->fetch(\PDO::FETCH_OBJ);

            if (!$submission || (int)$submission->worker_id !== $workerId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
            }

            if ($submission->status !== 'in_progress') {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
            }

            if (strtotime($submission->deadline_at) < time()) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'مهلت ارسال به پایان رسیده.'];
            }

            if (!empty($proofData['proof_file_hash'])) {
                if ($this->submissionModel->submission_isDuplicateImage(
                    $proofData['proof_file_hash'],
                    $submission->task_id
                )) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'این مدرک قبلاً ارسال شده است.'];
                }
            }

            $updateData = [
                'proof_text' => $proofData['proof_text'] ?? null,
                'proof_file' => $proofData['proof_file'] ?? null,
                'proof_file_hash' => $proofData['proof_file_hash'] ?? null,
                'submitted_at' => date('Y-m-d H:i:s'),
                'status' => 'submitted',
            ];

            $this->submissionModel->submission_update($submissionId, $updateData);

            $this->db->commit();

            $this->logger->info('Proof submitted', [
                'submission_id' => $submissionId,
                'worker_id' => $workerId,
            ]);

            $task = $this->taskModel->find($submission->task_id);
            $this->notificationService->send(
                $task->user_id,
                'task_proof_submitted',
                'مدرک جدید دریافت شد',
                "مدرک جدیدی برای وظیفه «{$task->title}» ارسال شد و منتظر بررسی است.",
                [
                    'task_id' => $task->id,
                    'submission_id' => $submissionId,
                    'url' => "/user/custom-tasks/submissions/{$submissionId}"
                ]
            );

            $autoApproveHours = (int) $this->settingService->get('custom_task_auto_approve_hours', 48);
            
            return [
                'success' => true,
                'message' => 'مدرک شما با موفقیت ارسال شد.',
                'auto_approve_info' => "در صورت عدم بررسی توسط تبلیغ‌کننده تا {$autoApproveHours} ساعت آینده، به‌صورت خودکار تایید خواهد شد.",
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('task.proof_submission.failed', [
                'channel' => 'task',
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در ارسال مدرک.'];
        }
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
