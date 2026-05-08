<?php

namespace App\Services;

use App\Models\CustomTaskModel;
use App\Models\CustomTaskSubmissionModel;
use App\Models\CustomTaskAnalyticsModel;
use App\Models\Dispute;
use App\Models\TaskRating;
use App\Models\InteractionModel;
use App\Services\WalletService;
use App\Services\User\UserLevelService;
use App\Services\Shared\ReferralService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\SettingService;
use App\Services\Notification\NotificationService;
use Core\Database;
use Core\Logger;

/**
 * سرویس مدیریت Custom Tasks
 * نسخه بهبودیافته با استفاده از ساختار موجود پروژه
 */
class CustomTaskService extends \App\Services\BaseService
{
    private CustomTaskModel $taskModel;
    private CustomTaskSubmissionModel $submissionModel;
    private CustomTaskAnalyticsModel $analyticsModel;
    private Dispute $disputeModel;
    private TaskRating $ratingModel;
    private InteractionModel $interactionModel;
    private Database $db;
    private WalletService $walletService;
    private UserLevelService $userLevelService;
    private ReferralService $referralService;
    private NotificationService $notificationService;
    
    // استفاده از سیستم Anti-Fraud موجود
    private BrowserFingerprintService $fingerprintService;
    private IPQualityService $ipQualityService;
    private SessionAnomalyService $sessionAnomalyService;
    private SettingService $settingService;

    public function __construct(
        Logger $logger,
        Database $db,
        WalletService $walletService,
        UserLevelService $userLevelService,
        ReferralService $referralService,
        NotificationService $notificationService,
        CustomTaskModel $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        CustomTaskAnalyticsModel $analyticsModel,
        Dispute $disputeModel,
        TaskRating $ratingModel,
        InteractionModel $interactionModel,
        BrowserFingerprintService $fingerprintService,
        IPQualityService $ipQualityService,
        SessionAnomalyService $sessionAnomalyService,
        SettingService $settingService
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->walletService = $walletService;
        $this->userLevelService = $userLevelService;
        $this->referralService = $referralService;
        $this->notificationService = $notificationService;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->analyticsModel = $analyticsModel;
        $this->disputeModel = $disputeModel;
        $this->ratingModel = $ratingModel;
        $this->interactionModel = $interactionModel;
        $this->fingerprintService = $fingerprintService;
        $this->ipQualityService = $ipQualityService;
        $this->sessionAnomalyService = $sessionAnomalyService;
        $this->settingService = $settingService;
    }

    /**
     * ایجاد وظیفه جدید
     */
    public function createTask(int $creatorId, array $data): array
    {
        // بررسی فعال بودن از setting (نه کانفیگ!)
        if (!$this->settingService->get('custom_task_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم وظایف سفارشی غیرفعال است.'];
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_quantity'] ?? 1);

        // بررسی حداقل قیمت از setting
        $minPrice = $currency === 'usdt'
            ? (float) $this->settingService->get('custom_task_min_price_usdt', 0.50)
            : (float) $this->settingService->get('custom_task_min_price_irt', 5000);

        if ($pricePerTask < $minPrice) {
            $label = $currency === 'usdt' 
                ? number_format($minPrice, 2) . ' USDT' 
                : number_format($minPrice) . ' تومان';
            return ['success' => false, 'message' => "حداقل قیمت هر تسک {$label} است."];
        }

        // محاسبه بودجه - از setting
        $feePercent = (float) $this->settingService->get('custom_task_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $feeAmount = round($totalBudget * ($feePercent / 100), 2);
        $totalWithFee = $totalBudget + $feeAmount;

        try {
            $this->db->beginTransaction();

            // کسر بودجه از کیف پول
            $idempotencyKey = "ctask_budget_{$creatorId}_" . time() . '_' . bin2hex(random_bytes(4));
            
            $txId = $this->walletService->withdraw(
                $creatorId,
                $totalWithFee,
                $currency,
                [
                    'type' => 'task_budget',
                    'description' => "بودجه وظیفه: {$data['title']}",
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست.'];
            }

            // وضعیت اولیه از setting
            $status = $this->settingService->get('custom_task_auto_approve', 0) ? 'active' : 'pending_review';

            // ایجاد تسک با Model موجود
            $task = $this->taskModel->create([
                'creator_id' => $creatorId,
                'title' => $data['title'],
                'description' => $data['description'],
                'link' => $data['link'] ?? null,
                'task_type' => $data['task_type'] ?? 'custom',
                'proof_type' => $data['proof_type'] ?? 'screenshot',
                'proof_description' => $data['proof_description'] ?? null,
                'sample_image' => $data['sample_image'] ?? null,
                'price_per_task' => $pricePerTask,
                'currency' => $currency,
                'total_budget' => $totalBudget,
                'total_quantity' => $quantity,
                'deadline_hours' => $data['deadline_hours'] ?? 24,
                'country_restriction' => $data['country_restriction'] ?? null,
                'device_restriction' => $data['device_restriction'] ?? 'all',
                'os_restriction' => $data['os_restriction'] ?? null,
                'daily_limit_per_user' => $data['daily_limit_per_user'] ?? 1,
                'status' => $status,
                'site_fee_percent' => $feePercent,
                'site_fee_amount' => $feeAmount,
            ]);

            if (!$task) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد وظیفه.'];
            }

            $this->db->commit();

            $this->logger->info('Custom task created', [
                'task_id' => $task->id,
                'creator_id' => $creatorId,
                'budget' => $totalWithFee,
            ]);

            // ارسال نوتیفیکیشن به سازنده
            $this->notificationService->send(
                $creatorId,
                'task_created',
                'وظیفه شما با موفقیت ثبت شد',
                "وظیفه «{$data['title']}» با وضعیت {$status} ثبت شد.",
                [
                    'task_id' => $task->id,
                    'url' => "/user/custom-tasks/my-tasks/{$task->id}"
                ]
            );

            return [
                'success' => true,
                'message' => 'وظیفه با موفقیت ثبت شد.',
                'task' => $task,
            ];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.create.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در ثبت وظیفه: ' . $e->getMessage()];
}
    }

    /**
     * شروع انجام تسک با استفاده از Anti-Fraud موجود
     */
    public function startTask(int $taskId, int $workerId): array
    {
        $task = $this->taskModel->find($taskId);

        if (!$task || $task->status !== 'active') {
            return ['success' => false, 'message' => 'وظیفه فعال نیست.'];
        }

        if ($task->creator_id === $workerId) {
            return ['success' => false, 'message' => 'نمی‌توانید وظیفه خودتان را انجام دهید.'];
        }

        // بررسی تکراری
        if ($this->submissionModel->submission_hasWorkerDone($taskId, $workerId)) {
            return ['success' => false, 'message' => 'شما قبلاً این وظیفه را انجام داده‌اید.'];
        }

        // بررسی سقف روزانه - از setting
        $maxDaily = (int) $this->settingService->get('custom_task_max_daily_submissions', 20);
        if ($this->submissionModel->submission_todayCount($workerId) >= $maxDaily) {
            return ['success' => false, 'message' => "سقف انجام تسک روزانه ({$maxDaily}) تکمیل شده."];
        }

        // ظرفیت باقی‌مانده
        $remaining = $task->remaining_count;
        if ($remaining <= 0) {
            return ['success' => false, 'message' => 'ظرفیت این وظیفه تکمیل شده.'];
        }

        // استفاده از Anti-Fraud موجود پروژه
        $riskScore = $this->calculateRiskScore($workerId, $taskId);
        
        // بررسی آستانه ریسک - از setting
        $riskThreshold = (float) $this->settingService->get('custom_task_risk_threshold', 70.0);
        if ($riskScore >= $riskThreshold) {
            $this->logger->warning('High risk task start attempt', [
                'worker_id' => $workerId,
                'task_id' => $taskId,
                'risk_score' => $riskScore,
            ]);
            // ارسال به صف بررسی دستی یا رد مستقیم
            $autoReject = $this->settingService->get('custom_task_auto_reject_high_risk', 0);
            if ($autoReject) {
                return ['success' => false, 'message' => 'امتیاز ریسک شما بالا است. لطفاً بعداً تلاش کنید.'];
            }
        }

        try {
            $this->db->beginTransaction();

            $deadlineAt = date('Y-m-d H:i:s', strtotime("+{$task->deadline_hours} hours"));
            $idempotencyKey = "ctask_sub_{$taskId}_{$workerId}_" . date('Ymd_His');

            // بررسی تکراری idempotency
            if ($this->submissionModel->submission_checkIdempotency($idempotencyKey)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست تکراری است.'];
            }

            // محاسبه پاداش با بونوس
            $rewardAmount = $this->userLevelService->applyEarningBonus(
                $workerId,
                (float) $task->price_per_task
            );

            // ایجاد submission
            $submission = $this->submissionModel->submission_create([
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'deadline_at' => $deadlineAt,
                'reward_amount' => $rewardAmount,
                'reward_currency' => $task->currency,
                'idempotency_key' => $idempotencyKey,
                'worker_ip' => get_client_ip(),
                'worker_device' => get_user_agent(),
                'worker_fingerprint' => generate_device_fingerprint(),
            ]);

            if (!$submission) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در شروع وظیفه: سابمیشن ثبت نشد.'];
            }

            // No pending_count column exists in custom_tasks table, submission is tracked in custom_task_submissions instead.

            $this->db->commit();

            $this->logger->info('Task started', [
                'submission_id' => $submission->id,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'risk_score' => $riskScore,
            ]);

            return [
                'success' => true,
                'message' => 'وظیفه با موفقیت شروع شد.',
                'submission_id' => $submission->id,
                'deadline' => $deadlineAt,
            ];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.start.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در شروع وظیفه: ' . $e->getMessage()];
}
    }

    /**
     * محاسبه ریسک با استفاده از Anti-Fraud موجود
     */
    private function calculateRiskScore(int $userId, int $taskId): float
    {
        $scores = [];

        // 1. بررسی کیفیت IP از سرویس موجود
        try {
            $ipQuality = $this->ipQualityService->checkIP(get_client_ip());
            $scores[] = $ipQuality['fraud_score'] ?? 0;
        } catch (\Exception $e) {
            $this->logger->warning('IP quality check failed', ['error' => $e->getMessage()]);
        }

        // 2. بررسی Browser Fingerprint
        try {
            $fingerprint = generate_device_fingerprint();
            $fpCheck = $this->fingerprintService->analyze($userId, $fingerprint);
            if ($fpCheck['is_suspicious']) {
                $scores[] = 60; // امتیاز بالا برای fingerprint مشکوک
            }
        } catch (\Exception $e) {
            $this->logger->warning('Fingerprint check failed', ['error' => $e->getMessage()]);
        }

        // 3. بررسی Session Anomaly
        try {
            $sessionCheck = $this->sessionAnomalyService->analyze($userId, (string)($_SESSION['session_id'] ?? ''));
            if ($sessionCheck['is_anomaly']) {
                $scores[] = 50;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Session anomaly check failed', ['error' => $e->getMessage()]);
        }

        // 4. بررسی تکراری بودن (سرعت submission)
        $recentCount = $this->submissionModel->submission_todayCount($userId);
        $dailyLimit = (int) $this->settingService->get('custom_task_max_daily_submissions', 20);
        if ($recentCount > $dailyLimit * 0.8) {
            $scores[] = 40; // نزدیک به سقف
        }

        // محاسبه میانگین
        return empty($scores) ? 0 : round(array_sum($scores) / count($scores), 2);
    }

    /**
     * ارسال مدرک
     */
    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission || $submission->worker_id !== $workerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'in_progress') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        // بررسی deadline
        if (strtotime($submission->deadline_at) < time()) {
            return ['success' => false, 'message' => 'مهلت ارسال به پایان رسیده.'];
        }

        // بررسی تکراری بودن proof
        if (!empty($proofData['proof_file_hash'])) {
            if ($this->submissionModel->submission_isDuplicateImage(
                $proofData['proof_file_hash'],
                $submission->task_id
            )) {
                return ['success' => false, 'message' => 'این مدرک قبلاً ارسال شده است.'];
            }
        }

        try {
            $this->db->beginTransaction();

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

            // ارسال نوتیفیکیشن به سازنده تسک
            $task = $this->taskModel->find($submission->task_id);
            $this->notificationService->send(
                $task->creator_id,
                'task_proof_submitted',
                'مدرک جدید دریافت شد',
                "مدرک جدیدی برای وظیفه «{$task->title}» ارسال شد و منتظر بررسی است.",
                [
                    'task_id' => $task->id,
                    'submission_id' => $submissionId,
                    'url' => "/user/custom-tasks/submissions/{$submissionId}"
                ]
            );

            // بررسی تایید خودکار - از setting
            $autoApproveHours = (int) $this->settingService->get('custom_task_auto_approve_hours', 48);
            
            return [
                'success' => true,
                'message' => 'مدرک شما با موفقیت ارسال شد.',
                'auto_approve_info' => "در صورت عدم بررسی توسط تبلیغ‌دهنده تا {$autoApproveHours} ساعت آینده، به‌صورت خودکار تایید خواهد شد.",
            ];

        }catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.proof_submission.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در ارسال مدرک.'];
}
    }

    /**
     * بررسی و تایید/رد
     */
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

    /**
     * تایید submission
     */
    private function approveSubmission(object $submission): array
    {
        try {
            $this->db->beginTransaction();

            // به‌روزرسانی وضعیت
            $this->submissionModel->submission_update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            // پرداخت پاداش
            $this->payWorkerReward($submission);

            // به‌روزرسانی آمار تسک
            $task = $this->taskModel->find($submission->task_id);
            $this->taskModel->update($task->id, [
                'completed_quantity' => ($task->completed_quantity ?? 0) + 1,
                'remaining_budget' => max(0, ($task->remaining_budget ?? 0) - $submission->reward_amount),
            ]);

            $this->db->commit();

            $this->logger->info('Submission approved', [
                'submission_id' => $submission->id,
                'worker_id' => $submission->worker_id,
            ]);

            // ارسال نوتیفیکیشن به انجام‌دهنده
            $this->notificationService->send(
                $submission->worker_id,
                'task_submission_approved',
                'مدرک شما تایید شد',
                "مدرک شما برای وظیفه «{$submission->task_title}» تایید شد و پاداش پرداخت گردید.",
                [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reward' => $submission->reward_amount,
                    'currency' => $submission->reward_currency,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            );

            return ['success' => true, 'message' => 'درخواست تایید شد.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.approval.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در تایید: ' . $e->getMessage()];
}
    }

    /**
     * رد submission
     */
    private function rejectSubmission(object $submission, ?string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $this->submissionModel->submission_update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason,
            ]);

            // کاهش شمارنده pending
            $task = $this->taskModel->find($submission->task_id);
            $this->taskModel->update($task->id, [
                'pending_count' => max(0, $task->pending_count - 1),
            ]);

            $this->db->commit();

            $this->logger->info('Submission rejected', [
                'submission_id' => $submission->id,
                'reason' => $reason,
            ]);

            // ارسال نوتیفیکیشن به انجام‌دهنده
            $this->notificationService->send(
                $submission->worker_id,
                'task_submission_rejected',
                'مدرک شما رد شد',
                "متأسفانه مدرک شما برای وظیفه «{$submission->task_title}» رد شد. دلیل: {$reason}",
                [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reason' => $reason,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            );

            return ['success' => true, 'message' => 'درخواست رد شد.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.rejection.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در رد درخواست.'];
}
    }

    /**
     * پرداخت پاداش
     */
    private function payWorkerReward(object $submission): void
    {
        $idempotencyKey = "ctask_reward_{$submission->id}_" . time();

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

            // پرداخت کمیسیون
            $this->referralService->processMultiTierCommissions(
                $submission->worker_id,
                (float) $submission->reward_amount,
                $submission->reward_currency
            );
        }
    }

    // متدهای Query ساده برای Controller ها

    public function find(int $id): ?object
    {
        return $this->taskModel->find($id);
    }

    public function getAvailableTasks(int $workerId, array $filters, int $limit, int $offset): array
    {
        return $this->taskModel->getAvailable($workerId, $filters, $limit, $offset);
    }

    public function getMyTasks(int $creatorId, ?string $status, int $limit, int $offset): array
    {
        return $this->taskModel->getByCreator($creatorId, $status, $limit, $offset);
    }

    public function getMySubmissions(int $workerId, ?string $status, int $limit, int $offset): array
    {
        return $this->submissionModel->submission_getByWorker($workerId, $status, $limit, $offset);
    }

    /**
     * تایید اجباری توسط ادمین (برای حل اختلاف)
     */
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

            // به‌روزرسانی وضعیت
            $this->submissionModel->submission_update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            // پرداخت پاداش
            $this->payWorkerReward($submission);

            // به‌روزرسانی آمار تسک
            $task = $this->taskModel->find($submission->task_id);
            
            // اگر قبلا رد شده بود، pending_count تغییر نمیکنه
            $pendingDecrease = in_array($submission->status, ['submitted']) ? 1 : 0;
            
            $this->taskModel->update($task->id, [
                'completed_count' => $task->completed_count + 1,
                'pending_count' => max(0, $task->pending_count - $pendingDecrease),
                'spent_budget' => $task->spent_budget + $submission->reward_amount,
            ]);

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
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['ok' => false, 'message' => 'خطا در تایید.'];
}
    }

    /**
     * رد اجباری توسط ادمین (برای حل اختلاف)
     */
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

            // کاهش شمارنده pending
            $task = $this->taskModel->find($submission->task_id);
            
            $pendingDecrease = in_array($submission->status, ['submitted']) ? 1 : 0;
            
            $this->taskModel->update($task->id, [
                'pending_count' => max(0, $task->pending_count - $pendingDecrease),
            ]);

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
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['ok' => false, 'message' => 'خطا در رد درخواست.'];
}
    }

    /**
     * ثبت امتیاز برای یک submission
     */
    public function rateSubmission(int $submissionId, int $raterId, array $ratingData): array
    {
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

        // تشخیص نوع امتیاز
        $task = $this->taskModel->find($submission->task_id);
        
        if ($raterId == $task->creator_id) {
            // creator داره به worker امتیاز میده
            $ratingType = 'worker';
            $ratedUserId = $submission->worker_id;
        } elseif ($raterId == $submission->worker_id) {
            // worker داره به creator امتیاز میده
            $ratingType = 'creator';
            $ratedUserId = $task->creator_id;
        } else {
            return ['success' => false, 'message' => 'شما مجاز به امتیازدهی نیستید.'];
        }

        try {
            // بررسی تکراری
            if ($this->ratingModel->hasRated($submissionId, $raterId, $ratingType)) {
                return ['success' => false, 'message' => 'شما قبلاً امتیاز داده‌اید.'];
            }

            $this->db->beginTransaction();

            $ratingObj = $this->ratingModel->create([
                'task_id' => $task->id,
                'submission_id' => $submissionId,
                'rater_id' => $raterId,
                'rated_user_id' => $ratedUserId,
                'rating_type' => $ratingType,
                'rating' => $rating,
                'review_text' => $reviewText ?: null,
            ]);

            if (!$ratingObj) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز.'];
            }

            // به‌روزرسانی میانگین امتیاز تسک
            $this->updateTaskRating($task->id);

            $this->db->commit();

            // ارسال نوتیفیکیشن
            $this->notificationService->send(
                $ratedUserId,
                'new_rating_received',
                'امتیاز جدید دریافت کردید',
                "امتیاز {$rating} ستاره برای وظیفه «{$task->title}» دریافت کردید.",
                [
                    'rating_id' => $ratingObj->id,
                    'task_id' => $task->id,
                    'rating' => $rating
                ]
            );

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

    /**
     * به‌روزرسانی میانگین امتیاز یک تسک
     */
    private function updateTaskRating(int $taskId): void
    {
        $this->taskModel->updateTaskRatingStats($taskId);
    }

    /**
     * ثبت بازدید تسک
     */
    public function recordTaskView(int $taskId, int $userId): void
    {
        $this->analyticsModel->recordTaskView($taskId);
    }

    /**
     * افزودن/حذف از علاقه‌مندی‌ها
     */
    public function toggleFavorite(int $taskId, int $userId): array
    {
        try {
            $isCurrentlyFavorite = $this->taskModel->isTaskFavorited($taskId, $userId);

            $this->taskModel->beginTransaction();

            if ($isCurrentlyFavorite) {
                // حذف از علاقه‌مندی‌ها
                $success = $this->taskModel->removeFromFavorites($taskId, $userId);
                $message = 'از علاقه‌مندی‌ها حذف شد.';
                $isFavorite = false;
            } else {
                // اضافه به علاقه‌مندی‌ها
                $success = $this->taskModel->addToFavorites($taskId, $userId);
                $message = 'به علاقه‌مندی‌ها اضافه شد.';
                $isFavorite = true;
            }

            if ($success) {
                $this->taskModel->commit();
                return [
                    'success' => true,
                    'message' => $message,
                    'is_favorite' => $isFavorite
                ];
            } else {
                $this->taskModel->rollBack();
                return ['success' => false, 'message' => 'خطا در عملیات.'];
            }

        } catch (\Exception $e) {
            $this->taskModel->rollBack();
            return ['success' => false, 'message' => 'خطا در عملیات.'];
        }
    }

    /**
     * دریافت آمار تفصیلی یک تسک
     */
    public function getTaskAnalytics(int $taskId, int $days = 30): array
    {
        $analytics = $this->analyticsModel->getTaskAnalytics($taskId);

        // توزیع امتیازها
        $ratings = $this->ratingModel->getTaskRatings($taskId, 100, 0);

        return [
            'overall' => $analytics['overall'],
            'daily' => $analytics['daily'],
            'ratings' => $ratings,
        ];
    }

    /**
     * Auto-approve submissions که مدت زیادی بررسی نشده‌اند
     */
    public function autoApproveOldSubmissions(): int
    {
        $hours = (int) $this->settingService->get('custom_task_auto_approve_hours', 48);

        $submissions = $this->submissionModel->getOldSubmissionsForAutoApproval($hours);

        $approved = 0;
        foreach ($submissions as $sub) {
            $fullSubmission = $this->submissionModel->submission_find($sub->id);
            if ($fullSubmission) {
                $result = $this->approveSubmission($fullSubmission);
                if ($result['success']) {
                    $approved++;

                    // نوتیفیکیشن تایید خودکار
                    $this->notificationService->send(
                        $fullSubmission->worker_id,
                        'auto_approved',
                        'مدرک شما به صورت خودکار تایید شد',
                        "مدرک شما برای وظیفه «{$fullSubmission->task_title}» به دلیل عدم بررسی توسط تبلیغ‌دهنده، خودکار تایید و پاداش پرداخت شد.",
                        [
                            'submission_id' => $fullSubmission->id,
                            'task_id' => $fullSubmission->task_id
                        ]
                    );
                }
            }
        }

        return $approved;
    }

    /**
     * منقضی کردن submission های deadline گذشته
     */
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

                // کاهش pending count
                $task = $this->taskModel->find($sub->task_id);
                if ($task) {
                    $this->taskModel->update($task->id, [
                        'pending_count' => max(0, $task->pending_count - 1),
                    ]);
                }

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

    // ═══════════════════════════════════════════════════════════════════════
    //  ADMIN METHODS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * گرفتن جزئیات کامل تسک برای ادمین
     */
    public function getTaskDetailsForAdmin(int $taskId): ?array
    {
        $task = $this->taskModel->find($taskId);
        if (!$task) {
            return null;
        }

        $submissions = $this->submissionModel->submission_getByTask($taskId, null, 50, 0);

        return [
            'task' => $task,
            'submissions' => $submissions,
        ];
    }

    /**
     * تأیید تسک توسط ادمین
     */
    public function approveTask(int $taskId, int $adminId): array
    {
        $task = $this->taskModel->find($taskId);
        if (!$task) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        $updated = $this->taskModel->update($taskId, [
            'status' => 'active',
            'approved_by' => $adminId,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$updated) {
            return ['success' => false, 'message' => 'خطا در بروزرسانی تسک'];
        }

        return ['success' => true, 'message' => 'تسک فعال شد'];
    }

    /**
     * رد تسک توسط ادمین
     */
    public function rejectTask(int $taskId, int $adminId, ?string $reason = null): array
    {
        $task = $this->taskModel->find($taskId);
        if (!$task) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        $this->db->beginTransaction();

        try {
            // بروزرسانی تسک
            $this->taskModel->update($taskId, [
                'status' => 'rejected',
                'rejection_reason' => $reason ?? 'عدم رعایت قوانین',
            ]);

            // بازگشت بودجه
            $totalReturn = (float) $task->total_budget + (float) $task->site_fee_amount;
            if ($totalReturn > 0) {
                $this->walletService->deposit(
                    (int) $task->creator_id,
                    $totalReturn,
                    $task->currency,
                    [
                        'type' => 'task_refund',
                        'description' => "بازگشت بودجه تسک #{$taskId}",
                        'idempotency_key' => "ctask_refund_{$taskId}",
                    ]
                );
            }

            $this->db->commit();
            return ['success' => true, 'message' => 'تسک رد شد و بودجه بازگردانده شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'خطا در رد تسک: ' . $e->getMessage()];
        }
    }

    /**
     * گرفتن آمار کلی برای ادمین
     */
    public function getAdminStats(): array
    {
        // آمار تسک‌ها
        $taskStats = [
            'total_tasks' => $this->taskModel->adminCount([]),
            'active_tasks' => $this->taskModel->adminCount(['status' => 'active']),
            'pending_tasks' => $this->taskModel->adminCount(['status' => 'pending_review']),
            'completed_tasks' => $this->taskModel->adminCount(['status' => 'completed']),
        ];

        // آمار submission ها
        $submissionStats = $this->submissionModel->getAdminSubmissionStats();

        return [
            'success' => true,
            'stats' => $taskStats,
            'submissions' => $submissionStats,
        ];
    }

    /**
     * گرفتن داده‌های آنالیتیکس برای ادمین
     */
    public function getAdminAnalytics(): array
    {
        // آمار کلی تسک‌ها
        $taskStats = $this->analyticsModel->getAdminTaskStats();

        // آمار submission ها
        $submissionStats = $this->submissionModel->getAdminSubmissionStats();

        return [
            'taskStats' => $taskStats,
            'submissionStats' => $submissionStats,
        ];
    }

    /**
     * توقف تسک توسط ادمین
     */
    public function pauseTask(int $taskId, int $adminId): array
    {
        $task = $this->taskModel->find($taskId);
        if (!$task) {
            return ['ok' => false, 'message' => 'تسک یافت نشد'];
        }

        $updated = $this->taskModel->update($taskId, ['status' => 'paused']);

        return ['ok' => $updated, 'message' => $updated ? 'تسک متوقف شد' : 'خطا در توقف تسک'];
    }

    /**
     * ایجاد dispute برای یک submission رد شده
     */
    public function createDisputeForSubmission(int $submissionId, int $workerId, string $reason): array
    {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission || $submission->worker_id !== $workerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'rejected') {
            return ['success' => false, 'message' => 'فقط می‌توانید برای submission های رد شده dispute ایجاد کنید.'];
        }

        // بررسی وجود dispute باز
        if ($this->disputeModel->hasOpenTaskDispute($submission->task_id)) {
            return ['success' => false, 'message' => 'یک dispute باز برای این تسک وجود دارد.'];
        }

        try {
            $this->db->beginTransaction();

            $dispute = $this->disputeModel->create([
                'ref_type' => 'task',
                'ref_id' => $submission->task_id,
                'submission_id' => $submissionId,
                'initiator_id' => $workerId,
                'reason' => $reason,
                'status' => 'open',
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$dispute) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد dispute.'];
            }

            // به‌روزرسانی وضعیت submission
            $this->submissionModel->submission_update($submissionId, [
                'status' => 'disputed',
            ]);

            $this->db->commit();

            $this->logger->info('Dispute created for submission', [
                'dispute_id' => $dispute->id,
                'submission_id' => $submissionId,
                'worker_id' => $workerId,
            ]);

            // ارسال نوتیفیکیشن به creator
            $task = $this->taskModel->find($submission->task_id);
            $this->notificationService->send(
                $task->creator_id,
                'dispute_opened',
                'اختلاف جدید دریافت شد',
                "یک اختلاف برای وظیفه «{$task->title}» باز شده است.",
                [
                    'dispute_id' => $dispute->id,
                    'task_id' => $task->id,
                    'submission_id' => $submissionId,
                    'url' => "/user/custom-tasks/disputes/{$dispute->id}"
                ]
            );

            return [
                'success' => true,
                'message' => 'dispute با موفقیت ایجاد شد.',
                'dispute' => $dispute,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('dispute.create.failed', [
                'submission_id' => $submissionId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در ایجاد dispute.'];
        }
    }

    public function getStatusLabels(): array
    {
        return $this->taskModel->statusLabels();
    }

    public function getStatusClasses(): array
    {
        return $this->taskModel->statusClasses();
    }

    public function getTaskTypes(): array
    {
        return $this->taskModel->taskTypes();
    }

    public function getProofTypes(): array
    {
        return $this->taskModel->proofTypes();
    }

    public function countAvailableTasks(int $workerId, array $filters): int
    {
        return $this->taskModel->countAvailable($workerId, $filters);
    }

    public function getSubmissionsByTask(int $taskId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        return $this->taskModel->submission_getByTask($taskId, $status, $limit, $offset);
    }

    public function getUserFavorites(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->interactionModel->getUserFavorites($userId, $limit, $offset);
    }

    public function countUserFavorites(int $userId): int
    {
        return $this->interactionModel->countUserFavorites($userId);
    }

    public function reportTask(int $taskId, int $userId, string $reason, string $description): array
    {
        if ($this->interactionModel->hasPendingTaskReport($taskId, $userId)) {
            return ['success' => false, 'message' => 'شما قبلاً این تسک را گزارش کرده‌اید.'];
        }

        $report = $this->interactionModel->createTaskReport([
            'task_id' => $taskId,
            'reporter_id' => $userId,
            'reason' => $reason,
            'description' => $description,
        ]);

        if ($report) {
            return ['success' => true, 'message' => 'گزارش شما ثبت شد و در اسرع وقت بررسی خواهد شد.'];
        }

        return ['success' => false, 'message' => 'خطا در ثبت گزارش.'];
    }
}

