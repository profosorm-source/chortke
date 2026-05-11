<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ads;
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
 * Ø³Ø±ÙˆÛŒØ³ Ù…Ø¯ÛŒØ±ÛŒØª Custom Tasks
 * Ù†Ø³Ø®Ù‡ Ø¨Ù‡Ø¨ÙˆØ¯ÛŒØ§ÙØªÙ‡ Ø¨Ø§ Ø§Ø³ØªÙØ§Ø¯Ù‡ Ø§Ø² Ø³Ø§Ø®ØªØ§Ø± Ù…ÙˆØ¬ÙˆØ¯ Ù¾Ø±ÙˆÚ˜Ù‡
 */
class CustomTaskService extends \App\Services\BaseService
{
    private Ads $taskModel;
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
    
    // Ø§Ø³ØªÙØ§Ø¯Ù‡ Ø§Ø² Ø³ÛŒØ³ØªÙ… Anti-Fraud Ù…ÙˆØ¬ÙˆØ¯
    private BrowserFingerprintService $fingerprintService;
    private IPQualityService $ipQualityService;
    private SessionAnomalyService $sessionAnomalyService;
    private SettingService $settingService;
    private \App\Services\XPEngine $xpEngine;
    private \Core\RateLimiter $rateLimiter;

    public function __construct(
        Logger $logger,
        Database $db,
        WalletService $walletService,
        UserLevelService $userLevelService,
        ReferralService $referralService,
        NotificationService $notificationService,
        Ads $taskModel,
        CustomTaskSubmissionModel $submissionModel,
        CustomTaskAnalyticsModel $analyticsModel,
        Dispute $disputeModel,
        TaskRating $ratingModel,
        InteractionModel $interactionModel,
        BrowserFingerprintService $fingerprintService,
        IPQualityService $ipQualityService,
        SessionAnomalyService $sessionAnomalyService,
        SettingService $settingService,
        \App\Services\XPEngine $xpEngine,
        \Core\RateLimiter $rateLimiter
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
        $this->xpEngine = $xpEngine;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Ø§ÛŒØ¬Ø§Ø¯ ÙˆØ¸ÛŒÙÙ‡ Ø¬Ø¯ÛŒØ¯
     */
    public function createTask(int $creatorId, array $data): array
    {
        // Ù…Ø­Ø¯ÙˆÛŒØª Ù†Ø±Ø® Ø¯Ø±Ø®ÙˆØ§Ø³Øª Ø§ÛŒØ¬Ø§Ø¯ ØªØ³Ú© (Ù…Ø«Ù„Ø§ Ø­Ø¯Ø§Ú©Ø«Ø± Ûµ ØªØ³Ú© Ø¯Ø± Ù‡Ø± ÛŒÚ© Ø³Ø§Ø¹Øª)
        if (!$this->rateLimiter->attempt('custom_task:create:' . $creatorId, 5, 60)) {
            $wait = ceil($this->rateLimiter->availableIn('custom_task:create:' . $creatorId) / 60);
            return ['success' => false, 'message' => "ØªØ¹Ø¯Ø§Ø¯ Ø¯Ø±Ø®ÙˆØ§Ø³Øªâ€ŒÙ‡Ø§ÛŒ Ø³Ø§Ø®Øª ØªØ³Ú© Ø´Ù…Ø§ Ø¨ÛŒØ´ Ø§Ø² Ø­Ø¯ Ù…Ø¬Ø§Ø² Ø§Ø³Øª. Ù„Ø·ÙØ§Ù‹ {$wait} Ø¯Ù‚ÛŒÙ‚Ù‡ Ø¯ÛŒÚ¯Ø± ØªÙ„Ø§Ø´ Ú©Ù†ÛŒØ¯."];
        }

        // Ø¨Ø±Ø±Ø³ÛŒ ÙØ¹Ø§Ù„ Ø¨ÙˆØ¯Ù† Ø§Ø² setting (Ù†Ù‡ Ú©Ø§Ù†ÙÛŒÚ¯!)
        if (!$this->settingService->get('custom_task_enabled', 1)) {
            return ['success' => false, 'message' => 'Ø³ÛŒØ³ØªÙ… ÙˆØ¸Ø§ÛŒÙ Ø³ÙØ§Ø±Ø´ÛŒ ØºÛŒØ±ÙØ¹Ø§Ù„ Ø§Ø³Øª.'];
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_quantity'] ?? 1);

        // Ø¨Ø±Ø±Ø³ÛŒ Ø­Ø¯Ø§Ù‚Ù„ Ù‚ÛŒÙ…Øª Ø§Ø² setting
        $minPrice = $currency === 'usdt'
            ? (float) $this->settingService->get('custom_task_min_price_usdt', 0.50)
            : (float) $this->settingService->get('custom_task_min_price_irt', 5000);

        if ($pricePerTask < $minPrice) {
            $label = $currency === 'usdt' 
                ? number_format($minPrice, 2) . ' USDT' 
                : number_format($minPrice) . ' ØªÙˆÙ…Ø§Ù†';
            return ['success' => false, 'message' => "Ø­Ø¯Ø§Ù‚Ù„ Ù‚ÛŒÙ…Øª Ù‡Ø± ØªØ³Ú© {$label} Ø§Ø³Øª."];
        }

        // Ù…Ø­Ø§Ø³Ø¨Ù‡ Ø¨ÙˆØ¯Ø¬Ù‡ - Ø§Ø² setting
        $feePercent = (float) $this->settingService->get('custom_task_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $feeAmount = round($totalBudget * ($feePercent / 100), 2);
        $totalWithFee = $totalBudget + $feeAmount;

        try {
            $this->db->beginTransaction();

            // Ú©Ø³Ø± Ø¨ÙˆØ¯Ø¬Ù‡ Ø§Ø² Ú©ÛŒÙ Ù¾ÙˆÙ„
            // Ú©Ø³Ø± Ø¨ÙˆØ¯Ø¬Ù‡ Ø§Ø² Ú©ÛŒÙ Ù¾ÙˆÙ„ - Ø§Ø³ØªÙØ§Ø¯Ù‡ Ø§Ø² Ù‡Ø´ Ø§Ù…Ù† Ø¯ÛŒØªØ§ÛŒ ØªØ³Ú© Ø¨Ø±Ø§ÛŒ Ø¬Ù„ÙˆÚ¯ÛŒØ±ÛŒ Ù‚Ø·Ø¹ÛŒ Ø§Ø² Ø¯Ø¨Ù„â€ŒÚ©Ù„ÛŒÚ©
            $idempotencyKey = \Core\IdempotencyKey::generateFromPayload('task_budget_allocation', [
                'creator_id' => $creatorId,
                'title' => $data['title'] ?? 'untitled',
                'amount' => $totalWithFee,
                'currency' => $currency
            ]);
            
            $txId = $this->walletService->withdraw(
                $creatorId,
                $totalWithFee,
                $currency,
                [
                    'type' => 'task_budget',
                    'description' => "Ø¨ÙˆØ¯Ø¬Ù‡ ÙˆØ¸ÛŒÙÙ‡: {$data['title']}",
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Ù…ÙˆØ¬ÙˆØ¯ÛŒ Ú©Ø§ÙÛŒ Ù†ÛŒØ³Øª.'];
            }

            // ÙˆØ¶Ø¹ÛŒØª Ø§ÙˆÙ„ÛŒÙ‡ Ø§Ø² setting
            $status = $this->settingService->get('custom_task_auto_approve', 0) ? 'active' : 'pending_review';

            // Ø§ÛŒØ¬Ø§Ø¯ ØªØ³Ú© Ø¨Ø§ Model ÛŒÚ©Ù¾Ø§Ø±Ú†Ù‡ Ø¬Ø¯ÛŒØ¯
            $task = $this->taskModel->create([
                'type' => 'custom_task',
                'user_id' => $creatorId,
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
                'remaining_budget' => $totalBudget,
                'total_count' => $quantity,
                'remaining_count' => $quantity,
                'deadline_hours' => $data['deadline_hours'] ?? 24,
                'country_restriction' => $data['country_restriction'] ?? null,
                'device_restriction' => $data['device_restriction'] ?? 'all',
                'os_restriction' => $data['os_restriction'] ?? null,
                'status' => ($status === 'pending_review') ? 'pending' : $status,
                'site_commission_percent' => $feePercent,
                'restrictions' => json_encode([
                    'daily_limit_per_user' => $data['daily_limit_per_user'] ?? 1,
                    'site_fee_amount' => $feeAmount,
                ]),
            ]);

            if (!$task) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø§ÛŒØ¬Ø§Ø¯ ÙˆØ¸ÛŒÙÙ‡.'];
            }

            $this->db->commit();

            $this->logger->info('Custom task created', [
                'task_id' => $task->id,
                'creator_id' => $creatorId,
                'budget' => $totalWithFee,
            ]);

            // Ø§Ø±Ø³Ø§Ù„ Ù†ÙˆØªÛŒÙÛŒÚ©ÛŒØ´Ù† Ø¨Ù‡ Ø³Ø§Ø²Ù†Ø¯Ù‡
            $this->notificationService->send(
                $creatorId,
                'task_created',
                'ÙˆØ¸ÛŒÙÙ‡ Ø´Ù…Ø§ Ø¨Ø§ Ù…ÙˆÙÙ‚ÛŒØª Ø«Ø¨Øª Ø´Ø¯',
                "ÙˆØ¸ÛŒÙÙ‡ Â«{$data['title']}Â» Ø¨Ø§ ÙˆØ¶Ø¹ÛŒØª {$status} Ø«Ø¨Øª Ø´Ø¯.",
                [
                    'task_id' => $task->id,
                    'url' => "/user/custom-tasks/my-tasks/{$task->id}"
                ]
            );

            return [
                'success' => true,
                'message' => 'ÙˆØ¸ÛŒÙÙ‡ Ø¨Ø§ Ù…ÙˆÙÙ‚ÛŒØª Ø«Ø¨Øª Ø´Ø¯.',
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
    return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø«Ø¨Øª ÙˆØ¸ÛŒÙÙ‡: ' . $e->getMessage()];
}
    }

    /**
     * Ø´Ø±ÙˆØ¹ Ø§Ù†Ø¬Ø§Ù… ØªØ³Ú© Ø¨Ø§ Ø§Ø³ØªÙØ§Ø¯Ù‡ Ø§Ø² Anti-Fraud Ù…ÙˆØ¬ÙˆØ¯
     */
    public function startTask(int $taskId, int $workerId): array
    {
        // Ù…Ø­Ø¯ÙˆØ¯ÛŒØª Ø´Ø±ÙˆØ¹ Ù‡Ù…Ø²Ù…Ø§Ù† ÛŒØ§ Ù¾ÛŒØ§Ù¾ÛŒ ØªØ³Ú©â€ŒÙ‡Ø§ (Ù…Ø«Ù„Ø§ Ø­Ø¯Ø§Ú©Ø«Ø± Û±Ûµ ØªÙ„Ø§Ø´ Ø¯Ø± Ûµ Ø¯Ù‚ÛŒÙ‚Ù‡)
        if (!$this->rateLimiter->attempt('custom_task:start:' . $workerId, 15, 5)) {
            return ['success' => false, 'message' => "ØªØ¹Ø¯Ø§Ø¯ ØªÙ„Ø§Ø´â€ŒÙ‡Ø§ÛŒ Ø´Ù…Ø§ Ø¨Ø±Ø§ÛŒ Ø´Ø±ÙˆØ¹ ØªØ³Ú©â€ŒÙ‡Ø§ÛŒ Ø¬Ø¯ÛŒØ¯ Ø¨ÛŒØ´ Ø§Ø² Ø­Ø¯ Ø§Ø³Øª. Ù„Ø·ÙØ§Ù‹ Ú©Ù…ÛŒ ØªØ£Ù…Ù„ Ú©Ù†ÛŒØ¯."];
        }

        $task = $this->taskModel->find($taskId);

        if (!$task || $task->status !== 'active') {
            return ['success' => false, 'message' => 'ÙˆØ¸ÛŒÙÙ‡ ÙØ¹Ø§Ù„ Ù†ÛŒØ³Øª.'];
        }

        if ($task->user_id === $workerId) {
            return ['success' => false, 'message' => 'Ù†Ù…ÛŒâ€ŒØªÙˆØ§Ù†ÛŒØ¯ ÙˆØ¸ÛŒÙÙ‡ Ø®ÙˆØ¯ØªØ§Ù† Ø±Ø§ Ø§Ù†Ø¬Ø§Ù… Ø¯Ù‡ÛŒØ¯.'];
        }

        // Ø¨Ø±Ø±Ø³ÛŒ ØªÚ©Ø±Ø§Ø±ÛŒ
        if ($this->submissionModel->submission_hasWorkerDone($taskId, $workerId)) {
            return ['success' => false, 'message' => 'Ø´Ù…Ø§ Ù‚Ø¨Ù„Ø§Ù‹ Ø§ÛŒÙ† ÙˆØ¸ÛŒÙÙ‡ Ø±Ø§ Ø§Ù†Ø¬Ø§Ù… Ø¯Ø§Ø¯Ù‡â€ŒØ§ÛŒØ¯.'];
        }

        // Ø¨Ø±Ø±Ø³ÛŒ Ø³Ù‚Ù Ø±ÙˆØ²Ø§Ù†Ù‡ - Ø§Ø² setting
        $maxDaily = (int) $this->settingService->get('custom_task_max_daily_submissions', 20);
        if ($this->submissionModel->submission_todayCount($workerId) >= $maxDaily) {
            return ['success' => false, 'message' => "Ø³Ù‚Ù Ø§Ù†Ø¬Ø§Ù… ØªØ³Ú© Ø±ÙˆØ²Ø§Ù†Ù‡ ({$maxDaily}) ØªÚ©Ù…ÛŒÙ„ Ø´Ø¯Ù‡."];
        }

        // Ø¸Ø±ÙÛŒØª Ø¨Ø§Ù‚ÛŒâ€ŒÙ…Ø§Ù†Ø¯Ù‡ - Ù…Ø­Ø§Ø³Ø¨Ù‡ Ø¯Ù‚ÛŒÙ‚
        $remaining = (int)$task->total_count - (int)$task->completed_count - (int)$task->pending_count;
        if ($remaining <= 0) {
            return ['success' => false, 'message' => 'Ø¸Ø±ÙÛŒØª Ø§ÛŒÙ† ÙˆØ¸ÛŒÙÙ‡ ØªÚ©Ù…ÛŒÙ„ Ø´Ø¯Ù‡.'];
        }

        // Ø§Ø³ØªÙØ§Ø¯Ù‡ Ø§Ø² Anti-Fraud Ù…ÙˆØ¬ÙˆØ¯ Ù¾Ø±ÙˆÚ˜Ù‡
        $riskScore = $this->calculateRiskScore($workerId, $taskId);
        
        // Ø¨Ø±Ø±Ø³ÛŒ Ø¢Ø³ØªØ§Ù†Ù‡ Ø±ÛŒØ³Ú© - Ø§Ø² setting
        $riskThreshold = (float) $this->settingService->get('custom_task_risk_threshold', 70.0);
        if ($riskScore >= $riskThreshold) {
            $this->logger->warning('High risk task start attempt', [
                'worker_id' => $workerId,
                'task_id' => $taskId,
                'risk_score' => $riskScore,
            ]);
            // Ø§Ø±Ø³Ø§Ù„ Ø¨Ù‡ ØµÙ Ø¨Ø±Ø±Ø³ÛŒ Ø¯Ø³ØªÛŒ ÛŒØ§ Ø±Ø¯ Ù…Ø³ØªÙ‚ÛŒÙ…
            $autoReject = $this->settingService->get('custom_task_auto_reject_high_risk', 0);
            if ($autoReject) {
                return ['success' => false, 'message' => 'Ø§Ù…ØªÛŒØ§Ø² Ø±ÛŒØ³Ú© Ø´Ù…Ø§ Ø¨Ø§Ù„Ø§ Ø§Ø³Øª. Ù„Ø·ÙØ§Ù‹ Ø¨Ø¹Ø¯Ø§Ù‹ ØªÙ„Ø§Ø´ Ú©Ù†ÛŒØ¯.'];
            }
        }

        try {
            $this->db->beginTransaction();

            $deadlineAt = date('Y-m-d H:i:s', strtotime("+{$task->deadline_hours} hours"));
            $idempotencyKey = "ctask_sub_{$taskId}_{$workerId}_" . date('Ymd_His');

            // Ø¨Ø±Ø±Ø³ÛŒ ØªÚ©Ø±Ø§Ø±ÛŒ idempotency
            if ($this->submissionModel->submission_checkIdempotency($idempotencyKey)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Ø¯Ø±Ø®ÙˆØ§Ø³Øª ØªÚ©Ø±Ø§Ø±ÛŒ Ø§Ø³Øª.'];
            }

            // Ù…Ø­Ø§Ø³Ø¨Ù‡ Ù¾Ø§Ø¯Ø§Ø´ Ø¨Ø§ Ø¨ÙˆÙ†ÙˆØ³
            $rewardAmount = $this->userLevelService->applyEarningBonus(
                $workerId,
                (float) $task->price_per_task
            );

            // Ø§ÛŒØ¬Ø§Ø¯ submission
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
                return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø´Ø±ÙˆØ¹ ÙˆØ¸ÛŒÙÙ‡: Ø³Ø§Ø¨Ù…ÛŒØ´Ù† Ø«Ø¨Øª Ù†Ø´Ø¯.'];
            }

            // Ø«Ø¨Øª Ø±Ø²Ø±Ùˆ Ø¸Ø±ÙÛŒØª Ø¯Ø± Ù…Ø¯Ù„ Ù…ØªÙ…Ø±Ú©Ø² Ads
            $this->taskModel->incrementPendingCount($taskId);

            $this->db->commit();

            $this->logger->info('Task started', [
                'submission_id' => $submission->id,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'risk_score' => $riskScore,
            ]);

            return [
                'success' => true,
                'message' => 'ÙˆØ¸ÛŒÙÙ‡ Ø¨Ø§ Ù…ÙˆÙÙ‚ÛŒØª Ø´Ø±ÙˆØ¹ Ø´Ø¯.',
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
    return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø´Ø±ÙˆØ¹ ÙˆØ¸ÛŒÙÙ‡: ' . $e->getMessage()];
}
    }

    /**
     * Ù…Ø­Ø§Ø³Ø¨Ù‡ Ø±ÛŒØ³Ú© Ø¨Ø§ Ø§Ø³ØªÙØ§Ø¯Ù‡ Ø§Ø² Anti-Fraud Ù…ÙˆØ¬ÙˆØ¯
     */
    private function calculateRiskScore(int $userId, int $taskId): float
    {
        $scores = [];

        // 1. Ø¨Ø±Ø±Ø³ÛŒ Ú©ÛŒÙÛŒØª IP Ø§Ø² Ø³Ø±ÙˆÛŒØ³ Ù…ÙˆØ¬ÙˆØ¯
        try {
            $ipQuality = $this->ipQualityService->checkIP(get_client_ip());
            $scores[] = $ipQuality['fraud_score'] ?? 0;
        } catch (\Exception $e) {
            $this->logger->warning('IP quality check failed', ['error' => $e->getMessage()]);
        }

        // 2. Ø¨Ø±Ø±Ø³ÛŒ Browser Fingerprint
        try {
            $fingerprint = generate_device_fingerprint();
            $fpCheck = $this->fingerprintService->analyze($userId, $fingerprint);
            if ($fpCheck['is_suspicious']) {
                $scores[] = 60; // Ø§Ù…ØªÛŒØ§Ø² Ø¨Ø§Ù„Ø§ Ø¨Ø±Ø§ÛŒ fingerprint Ù…Ø´Ú©ÙˆÚ©
            }
        } catch (\Exception $e) {
            $this->logger->warning('Fingerprint check failed', ['error' => $e->getMessage()]);
        }

        // 3. Ø¨Ø±Ø±Ø³ÛŒ Session Anomaly
        try {
            $sessionCheck = $this->sessionAnomalyService->analyze($userId, (string)($_SESSION['session_id'] ?? ''));
            if ($sessionCheck['is_anomaly']) {
                $scores[] = 50;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Session anomaly check failed', ['error' => $e->getMessage()]);
        }

        // 4. Ø¨Ø±Ø±Ø³ÛŒ ØªÚ©Ø±Ø§Ø±ÛŒ Ø¨ÙˆØ¯Ù† (Ø³Ø±Ø¹Øª submission)
        $recentCount = $this->submissionModel->submission_todayCount($userId);
        $dailyLimit = (int) $this->settingService->get('custom_task_max_daily_submissions', 20);
        if ($recentCount > $dailyLimit * 0.8) {
            $scores[] = 40; // Ù†Ø²Ø¯ÛŒÚ© Ø¨Ù‡ Ø³Ù‚Ù
        }

        // Ù…Ø­Ø§Ø³Ø¨Ù‡ Ù…ÛŒØ§Ù†Ú¯ÛŒÙ†
        return empty($scores) ? 0 : round(array_sum($scores) / count($scores), 2);
    }

    /**
     * Ø§Ø±Ø³Ø§Ù„ Ù…Ø¯Ø±Ú©
     */
    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        // Ù…Ø­Ø¯ÙˆØ¯ÛŒØª Ø¯ÙØ¹Ø§Øª Ø³Ø§Ø¨Ù…ÛŒØª Ù…Ø¯Ø±Ú© (Ù…Ø«Ù„Ø§ Û±Û° ØªÙ„Ø§Ø´ Ø¯Ø± Û±Û° Ø¯Ù‚ÛŒÙ‚Ù‡)
        if (!$this->rateLimiter->attempt('custom_task:submit:' . $workerId, 10, 10)) {
            $wait = ceil($this->rateLimiter->availableIn('custom_task:submit:' . $workerId) / 60);
            return ['success' => false, 'message' => "ØªØ¹Ø¯Ø§Ø¯ ØªÙ„Ø§Ø´â€ŒÙ‡Ø§ÛŒ Ø§Ø±Ø³Ø§Ù„ Ù¾Ø§Ø³Ø® Ø´Ù…Ø§ ÙØ±Ø§ØªØ± Ø§Ø² Ø­Ø¯ Ù…Ø¬Ø§Ø² Ø§Ø³Øª. Ù„Ø·ÙØ§Ù‹ {$wait} Ø¯Ù‚ÛŒÙ‚Ù‡ Ø¯ÛŒÚ¯Ø± Ø§Ù…ØªØ­Ø§Ù† Ú©Ù†ÛŒØ¯."];
        }

        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission || $submission->worker_id !== $workerId) {
            return ['success' => false, 'message' => 'Ø¯Ø³ØªØ±Ø³ÛŒ ØºÛŒØ±Ù…Ø¬Ø§Ø².'];
        }

        if ($submission->status !== 'in_progress') {
            return ['success' => false, 'message' => 'ÙˆØ¶Ø¹ÛŒØª Ù†Ø§Ù…Ø¹ØªØ¨Ø±.'];
        }

        // Ø¨Ø±Ø±Ø³ÛŒ deadline
        if (strtotime($submission->deadline_at) < time()) {
            return ['success' => false, 'message' => 'Ù…Ù‡Ù„Øª Ø§Ø±Ø³Ø§Ù„ Ø¨Ù‡ Ù¾Ø§ÛŒØ§Ù† Ø±Ø³ÛŒØ¯Ù‡.'];
        }

        // Ø¨Ø±Ø±Ø³ÛŒ ØªÚ©Ø±Ø§Ø±ÛŒ Ø¨ÙˆØ¯Ù† proof
        if (!empty($proofData['proof_file_hash'])) {
            if ($this->submissionModel->submission_isDuplicateImage(
                $proofData['proof_file_hash'],
                $submission->task_id
            )) {
                return ['success' => false, 'message' => 'Ø§ÛŒÙ† Ù…Ø¯Ø±Ú© Ù‚Ø¨Ù„Ø§Ù‹ Ø§Ø±Ø³Ø§Ù„ Ø´Ø¯Ù‡ Ø§Ø³Øª.'];
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

            // Ø§Ø±Ø³Ø§Ù„ Ù†ÙˆØªÛŒÙÛŒÚ©ÛŒØ´Ù† Ø¨Ù‡ Ø³Ø§Ø²Ù†Ø¯Ù‡ ØªØ³Ú©
            $task = $this->taskModel->find($submission->task_id);
            $this->notificationService->send(
                $task->user_id,
                'task_proof_submitted',
                'Ù…Ø¯Ø±Ú© Ø¬Ø¯ÛŒØ¯ Ø¯Ø±ÛŒØ§ÙØª Ø´Ø¯',
                "Ù…Ø¯Ø±Ú© Ø¬Ø¯ÛŒØ¯ÛŒ Ø¨Ø±Ø§ÛŒ ÙˆØ¸ÛŒÙÙ‡ Â«{$task->title}Â» Ø§Ø±Ø³Ø§Ù„ Ø´Ø¯ Ùˆ Ù…Ù†ØªØ¸Ø± Ø¨Ø±Ø±Ø³ÛŒ Ø§Ø³Øª.",
                [
                    'task_id' => $task->id,
                    'submission_id' => $submissionId,
                    'url' => "/user/custom-tasks/submissions/{$submissionId}"
                ]
            );

            // Ø¨Ø±Ø±Ø³ÛŒ ØªØ§ÛŒÛŒØ¯ Ø®ÙˆØ¯Ú©Ø§Ø± - Ø§Ø² setting
            $autoApproveHours = (int) $this->settingService->get('custom_task_auto_approve_hours', 48);
            
            return [
                'success' => true,
                'message' => 'Ù…Ø¯Ø±Ú© Ø´Ù…Ø§ Ø¨Ø§ Ù…ÙˆÙÙ‚ÛŒØª Ø§Ø±Ø³Ø§Ù„ Ø´Ø¯.',
                'auto_approve_info' => "Ø¯Ø± ØµÙˆØ±Øª Ø¹Ø¯Ù… Ø¨Ø±Ø±Ø³ÛŒ ØªÙˆØ³Ø· ØªØ¨Ù„ÛŒØºâ€ŒØ¯Ù‡Ù†Ø¯Ù‡ ØªØ§ {$autoApproveHours} Ø³Ø§Ø¹Øª Ø¢ÛŒÙ†Ø¯Ù‡ØŒ Ø¨Ù‡â€ŒØµÙˆØ±Øª Ø®ÙˆØ¯Ú©Ø§Ø± ØªØ§ÛŒÛŒØ¯ Ø®ÙˆØ§Ù‡Ø¯ Ø´Ø¯.",
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
    return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø§Ø±Ø³Ø§Ù„ Ù…Ø¯Ø±Ú©.'];
}
    }

    /**
     * Ø¨Ø±Ø±Ø³ÛŒ Ùˆ ØªØ§ÛŒÛŒØ¯/Ø±Ø¯
     */
    public function reviewSubmission(
        int $submissionId,
        int $reviewerId,
        string $decision,
        ?string $reason = null
    ): array {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'ÛŒØ§ÙØª Ù†Ø´Ø¯.'];
        }

        if ($submission->creator_id !== $reviewerId) {
            return ['success' => false, 'message' => 'Ø¯Ø³ØªØ±Ø³ÛŒ ØºÛŒØ±Ù…Ø¬Ø§Ø².'];
        }

        if ($submission->status !== 'submitted') {
            return ['success' => false, 'message' => 'ÙˆØ¶Ø¹ÛŒØª Ù†Ø§Ù…Ø¹ØªØ¨Ø±.'];
        }

        if (!in_array($decision, ['approve', 'reject'])) {
            return ['success' => false, 'message' => 'ØªØµÙ…ÛŒÙ… Ù†Ø§Ù…Ø¹ØªØ¨Ø±.'];
        }

        if ($decision === 'approve') {
            return $this->approveSubmission($submission);
        } else {
            return $this->rejectSubmission($submission, $reason);
        }
    }

    /**
     * ØªØ§ÛŒÛŒØ¯ submission
     */
    private function approveSubmission(object $submission): array
    {
        try {
            $this->db->beginTransaction();

            // Ø¨Ù‡â€ŒØ±ÙˆØ²Ø±Ø³Ø§Ù†ÛŒ ÙˆØ¶Ø¹ÛŒØª
            $this->submissionModel->submission_update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            // Ù¾Ø±Ø¯Ø§Ø®Øª Ù¾Ø§Ø¯Ø§Ø´
            $this->payWorkerReward($submission);

            // Ø¨Ù‡â€ŒØ±ÙˆØ²Ø±Ø³Ø§Ù†ÛŒ Ø¢Ù…Ø§Ø± ØªØ³Ú© - Ø§ØªÙ…ÛŒÚ© Ùˆ Ø§Ù…Ù†
            $this->taskModel->incrementCustomTaskCompletion($submission->task_id, (float)$submission->reward_amount);

            $this->db->commit();

            $this->logger->info('Submission approved', [
                'submission_id' => $submission->id,
                'worker_id' => $submission->worker_id,
            ]);

            // Ø§Ø±Ø³Ø§Ù„ Ù†ÙˆØªÛŒÙÛŒÚ©ÛŒØ´Ù† Ø¨Ù‡ Ø§Ù†Ø¬Ø§Ù…â€ŒØ¯Ù‡Ù†Ø¯Ù‡
            $this->notificationService->send(
                $submission->worker_id,
                'task_submission_approved',
                'Ù…Ø¯Ø±Ú© Ø´Ù…Ø§ ØªØ§ÛŒÛŒØ¯ Ø´Ø¯',
                "Ù…Ø¯Ø±Ú© Ø´Ù…Ø§ Ø¨Ø±Ø§ÛŒ ÙˆØ¸ÛŒÙÙ‡ Â«{$submission->task_title}Â» ØªØ§ÛŒÛŒØ¯ Ø´Ø¯ Ùˆ Ù¾Ø§Ø¯Ø§Ø´ Ù¾Ø±Ø¯Ø§Ø®Øª Ú¯Ø±Ø¯ÛŒØ¯.",
                [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reward' => $submission->reward_amount,
                    'currency' => $submission->reward_currency,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            );

            return ['success' => true, 'message' => 'Ø¯Ø±Ø®ÙˆØ§Ø³Øª ØªØ§ÛŒÛŒØ¯ Ø´Ø¯.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.approval.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± ØªØ§ÛŒÛŒØ¯: ' . $e->getMessage()];
}
    }

    /**
     * Ø±Ø¯ submission
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

            // Ø¢Ø²Ø§Ø¯Ø³Ø§Ø²ÛŒ Ø¸Ø±ÙÛŒØª Ø¯Ø± Ù…Ø¯Ù„ Ù…ØªÙ…Ø±Ú©Ø² Ads
            $this->taskModel->decrementPendingCount($submission->task_id);

            $this->db->commit();

            $this->logger->info('Submission rejected', [
                'submission_id' => $submission->id,
                'reason' => $reason,
            ]);

            // Ø§Ø±Ø³Ø§Ù„ Ù†ÙˆØªÛŒÙÛŒÚ©ÛŒØ´Ù† Ø¨Ù‡ Ø§Ù†Ø¬Ø§Ù…â€ŒØ¯Ù‡Ù†Ø¯Ù‡
            $this->notificationService->send(
                $submission->worker_id,
                'task_submission_rejected',
                'Ù…Ø¯Ø±Ú© Ø´Ù…Ø§ Ø±Ø¯ Ø´Ø¯',
                "Ù…ØªØ£Ø³ÙØ§Ù†Ù‡ Ù…Ø¯Ø±Ú© Ø´Ù…Ø§ Ø¨Ø±Ø§ÛŒ ÙˆØ¸ÛŒÙÙ‡ Â«{$submission->task_title}Â» Ø±Ø¯ Ø´Ø¯. Ø¯Ù„ÛŒÙ„: {$reason}",
                [
                    'submission_id' => $submission->id,
                    'task_id' => $submission->task_id,
                    'reason' => $reason,
                    'url' => "/user/custom-tasks/my-submissions/{$submission->id}"
                ]
            );

            return ['success' => true, 'message' => 'Ø¯Ø±Ø®ÙˆØ§Ø³Øª Ø±Ø¯ Ø´Ø¯.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.rejection.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø±Ø¯ Ø¯Ø±Ø®ÙˆØ§Ø³Øª.'];
}
    }

    /**
     * Ù¾Ø±Ø¯Ø§Ø®Øª Ù¾Ø§Ø¯Ø§Ø´
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
                'description' => "Ù¾Ø§Ø¯Ø§Ø´ ÙˆØ¸ÛŒÙÙ‡ #{$submission->task_id}",
                'idempotency_key' => $idempotencyKey,
            ]
        );

        if (isset($txId['success']) && $txId['success']) {
            $this->submissionModel->submission_update($submission->id, [
                'reward_paid' => 1,
                'reward_transaction_id' => $txId['transaction_id'],
            ]);

            // Ù¾Ø±Ø¯Ø§Ø®Øª Ù¾ÙˆØ±Ø³Ø§Ù†Øª Ø¯Ø§ÛŒÙ†Ø§Ù…ÛŒÚ© Ùˆ Ù…Ø§Ú˜ÙˆÙ„Ø§Ø± Ø±ÙØ±Ø§Ù„
            $this->referralService->processModularCommission(
                (int) $submission->worker_id,
                'custom_tasks',
                (float) $submission->reward_amount,
                $submission->reward_currency
            );
        } else {
            throw new \Exception('Ø®Ø·Ø§ Ø¯Ø± ÙˆØ§Ø±ÛŒØ² Ù¾Ø§Ø¯Ø§Ø´ Ø¨Ù‡ Ú©ÛŒÙ Ù¾ÙˆÙ„ Ø§Ù†Ø¬Ø§Ù…â€ŒØ¯Ù‡Ù†Ø¯Ù‡: ' . ($txId['message'] ?? 'Ù†Ø§Ù…Ø´Ø®Øµ'));
        }

        // ØªØ®ØµÛŒØµ Ø§Ù…ØªÛŒØ§Ø² ØªØ¬Ø±Ø¨Ù‡ (XP) Ú¯ÛŒÙ…ÛŒÙØ§ÛŒ Ø´Ø¯Ù‡
        $this->xpEngine->awardXP(
            (int) $submission->worker_id,
            'custom_tasks',
            'custom_task_approved'
        );
    }

    // Ù…ØªØ¯Ù‡Ø§ÛŒ Query Ø³Ø§Ø¯Ù‡ Ø¨Ø±Ø§ÛŒ Controller Ù‡Ø§

    public function find(int $id): ?object
    {
        return $this->taskModel->find($id);
    }

    public function getAvailableTasks(int $workerId, array $filters, int $limit, int $offset): array
    {
        return $this->taskModel->getAvailableCustomTasks($workerId, $filters, $limit, $offset);
    }

    public function getMyTasks(int $creatorId, ?string $status, int $limit, int $offset): array
    {
        return $this->taskModel->getByAdvertiser($creatorId, $limit, $offset, 'custom_task', $status);
    }

    public function getMySubmissions(int $workerId, ?string $status, int $limit, int $offset): array
    {
        return $this->submissionModel->submission_getByWorker($workerId, $status, $limit, $offset);
    }

    /**
     * ØªØ§ÛŒÛŒØ¯ Ø§Ø¬Ø¨Ø§Ø±ÛŒ ØªÙˆØ³Ø· Ø§Ø¯Ù…ÛŒÙ† (Ø¨Ø±Ø§ÛŒ Ø­Ù„ Ø§Ø®ØªÙ„Ø§Ù)
     */
    public function forceApproveSubmissionByAdmin(int $submissionId, int $adminId): array
    {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['ok' => false, 'message' => 'ÛŒØ§ÙØª Ù†Ø´Ø¯.'];
        }

        if (!in_array($submission->status, ['submitted', 'disputed', 'rejected'])) {
            return ['ok' => false, 'message' => 'ÙˆØ¶Ø¹ÛŒØª Ù†Ø§Ù…Ø¹ØªØ¨Ø±.'];
        }

        try {
            $this->db->beginTransaction();

            // Ø¨Ù‡â€ŒØ±ÙˆØ²Ø±Ø³Ø§Ù†ÛŒ ÙˆØ¶Ø¹ÛŒØª
            $this->submissionModel->submission_update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            // Ù¾Ø±Ø¯Ø§Ø®Øª Ù¾Ø§Ø¯Ø§Ø´
            $this->payWorkerReward($submission);

            // Ø¨Ù‡â€ŒØ±ÙˆØ²Ø±Ø³Ø§Ù†ÛŒ Ø¢Ù…Ø§Ø± ØªØ³Ú© - Ø§ØªÙ…ÛŒÚ© Ø¨Ø§ Ø¨Ø±Ø±Ø³ÛŒ Ø´Ø±Ø·ÛŒ Ú©Ø§Ù‡Ø´ Ø¸Ø±ÙÛŒØª
            $pendingDecrease = in_array($submission->status, ['submitted']) ? true : false;
            $this->taskModel->incrementCustomTaskCompletion($submission->task_id, (float)$submission->reward_amount, $pendingDecrease);

            $this->db->commit();

            $this->logger->info('Submission force approved by admin', [
                'submission_id' => $submission->id,
                'admin_id' => $adminId,
            ]);

            return ['ok' => true, 'message' => 'Ø¯Ø±Ø®ÙˆØ§Ø³Øª ØªÙˆØ³Ø· Ø§Ø¯Ù…ÛŒÙ† ØªØ§ÛŒÛŒØ¯ Ø´Ø¯.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.force_approval.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['ok' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± ØªØ§ÛŒÛŒØ¯.'];
}
    }

    /**
     * Ø±Ø¯ Ø§Ø¬Ø¨Ø§Ø±ÛŒ ØªÙˆØ³Ø· Ø§Ø¯Ù…ÛŒÙ† (Ø¨Ø±Ø§ÛŒ Ø­Ù„ Ø§Ø®ØªÙ„Ø§Ù)
     */
    public function forceRejectSubmissionByAdmin(int $submissionId, int $adminId, ?string $reason = null): array
    {
        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['ok' => false, 'message' => 'ÛŒØ§ÙØª Ù†Ø´Ø¯.'];
        }

        if (!in_array($submission->status, ['submitted', 'disputed', 'approved'])) {
            return ['ok' => false, 'message' => 'ÙˆØ¶Ø¹ÛŒØª Ù†Ø§Ù…Ø¹ØªØ¨Ø±.'];
        }

        try {
            $this->db->beginTransaction();

            $this->submissionModel->submission_update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason ?? 'Ø±Ø¯ Ø´Ø¯Ù‡ ØªÙˆØ³Ø· Ù…Ø¯ÛŒØ±ÛŒØª',
            ]);

            // Ú©Ø§Ù‡Ø´ Ø´Ù…Ø§Ø±Ù†Ø¯Ù‡ pending Ø¯Ø± ØµÙˆØ±Øª Ù†ÛŒØ§Ø²
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

            return ['ok' => true, 'message' => 'Ø¯Ø±Ø®ÙˆØ§Ø³Øª ØªÙˆØ³Ø· Ø§Ø¯Ù…ÛŒÙ† Ø±Ø¯ Ø´Ø¯.'];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('task.force_rejection.failed', [
        'channel' => 'task',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['ok' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø±Ø¯ Ø¯Ø±Ø®ÙˆØ§Ø³Øª.'];
}
    }

    /**
     * Ø«Ø¨Øª Ø§Ù…ØªÛŒØ§Ø² Ø¨Ø±Ø§ÛŒ ÛŒÚ© submission
     */
    public function rateSubmission(int $submissionId, int $raterId, array $ratingData): array
    {
        if (!$this->settingService->get('custom_task_rating_enabled', 1)) {
            return ['success' => false, 'message' => 'Ø³ÛŒØ³ØªÙ… Ø§Ù…ØªÛŒØ§Ø²Ø¯Ù‡ÛŒ ØºÛŒØ±ÙØ¹Ø§Ù„ Ø§Ø³Øª.'];
        }

        $submission = $this->submissionModel->submission_find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'ÛŒØ§ÙØª Ù†Ø´Ø¯.'];
        }

        if ($submission->status !== 'approved') {
            return ['success' => false, 'message' => 'ÙÙ‚Ø· Ù…ÛŒâ€ŒØªÙˆØ§Ù†ÛŒØ¯ Ø¨Ù‡ submission Ù‡Ø§ÛŒ ØªØ§ÛŒÛŒØ¯ Ø´Ø¯Ù‡ Ø§Ù…ØªÛŒØ§Ø² Ø¯Ù‡ÛŒØ¯.'];
        }

        $rating = (int) ($ratingData['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'Ø§Ù…ØªÛŒØ§Ø² Ø¨Ø§ÛŒØ¯ Ø¨ÛŒÙ† 1 ØªØ§ 5 Ø¨Ø§Ø´Ø¯.'];
        }

        $reviewText = trim($ratingData['review_text'] ?? '');
        $minLength = (int) $this->settingService->get('custom_task_min_rating_text_length', 20);
        
        if (!empty($reviewText) && mb_strlen($reviewText) < $minLength) {
            return ['success' => false, 'message' => "Ù…ØªÙ† Ù†Ø¸Ø± Ø¨Ø§ÛŒØ¯ Ø­Ø¯Ø§Ù‚Ù„ {$minLength} Ú©Ø§Ø±Ø§Ú©ØªØ± Ø¨Ø§Ø´Ø¯."];
        }

        // ØªØ´Ø®ÛŒØµ Ù†ÙˆØ¹ Ø§Ù…ØªÛŒØ§Ø²
        $task = $this->taskModel->find($submission->task_id);
        
        if ($raterId == $task->user_id) {
            // creator Ø¯Ø§Ø±Ù‡ Ø¨Ù‡ worker Ø§Ù…ØªÛŒØ§Ø² Ù…ÛŒØ¯Ù‡
            $ratingType = 'worker';
            $ratedUserId = $submission->worker_id;
        } elseif ($raterId == $submission->worker_id) {
            // worker Ø¯Ø§Ø±Ù‡ Ø¨Ù‡ creator Ø§Ù…ØªÛŒØ§Ø² Ù…ÛŒØ¯Ù‡
            $ratingType = 'creator';
            $ratedUserId = $task->user_id;
        } else {
            return ['success' => false, 'message' => 'Ø´Ù…Ø§ Ù…Ø¬Ø§Ø² Ø¨Ù‡ Ø§Ù…ØªÛŒØ§Ø²Ø¯Ù‡ÛŒ Ù†ÛŒØ³ØªÛŒØ¯.'];
        }

        try {
            // Ø¨Ø±Ø±Ø³ÛŒ ØªÚ©Ø±Ø§Ø±ÛŒ
            if ($this->ratingModel->hasRated($submissionId, $raterId, $ratingType)) {
                return ['success' => false, 'message' => 'Ø´Ù…Ø§ Ù‚Ø¨Ù„Ø§Ù‹ Ø§Ù…ØªÛŒØ§Ø² Ø¯Ø§Ø¯Ù‡â€ŒØ§ÛŒØ¯.'];
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
                return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø«Ø¨Øª Ø§Ù…ØªÛŒØ§Ø².'];
            }

            // Ø¨Ù‡â€ŒØ±ÙˆØ²Ø±Ø³Ø§Ù†ÛŒ Ù…ÛŒØ§Ù†Ú¯ÛŒÙ† Ø§Ù…ØªÛŒØ§Ø² ØªØ³Ú©
            $this->updateTaskRating($task->id);

            $this->db->commit();

            // Ø§Ø±Ø³Ø§Ù„ Ù†ÙˆØªÛŒÙ ÛŒÚ©ÛŒØ´Ù†
            $this->notificationService->send(
                $ratedUserId,
                'new_rating_received',
                'Ø§Ù…ØªÛŒØ§Ø² Ø¬Ø¯ÛŒØ¯ Ø¯Ø±ÛŒØ§Ù Øª Ú©Ø±Ø¯ÛŒØ¯',
                "Ø§Ù…ØªÛŒØ§Ø² {$rating} Ø³ØªØ§Ø±Ù‡ Ø¨Ø±Ø§ÛŒ ÙˆØ¸ÛŒÙ Ù‡ Â«{$task->title}Â» Ø¯Ø±ÛŒØ§Ù Øª Ú©Ø±Ø¯ÛŒØ¯.",
                [
                    'rating_id' => $ratingObj->id,
                    'task_id' => $task->id,
                    'rating' => $rating
                ]
            );

            return [
                'success' => true,
                'message' => 'Ø§Ù…ØªÛŒØ§Ø² Ø¨Ø§ Ù…ÙˆÙ Ù‚ÛŒØª Ø«Ø¨Øª Ø´Ø¯.',
                'rating' => $ratingObj
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('rating.create.failed', [
                'error' => $e->getMessage(),
                'submission_id' => $submissionId,
            ]);
            return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø«Ø¨Øª Ø§Ù…ØªÛŒØ§Ø².'];
        }
    }

    /**
     * Ø¨Ù‡â€ŒØ±ÙˆØ²Ø±Ø³Ø§Ù†ÛŒ Ù…ÛŒØ§Ù†Ú¯ÛŒÙ† Ø§Ù…ØªÛŒØ§Ø² ÛŒÚ© ØªØ³Ú©
     */
    private function updateTaskRating(int $taskId): void
    {
        $this->taskModel->updateTaskRatingStats($taskId);
    }

    /**
     * Ø«Ø¨Øª Ø¨Ø§Ø²Ø¯ÛŒØ¯ ØªØ³Ú©
     */
    public function recordTaskView(int $taskId, int $userId): void
    {
        $this->analyticsModel->recordTaskView($taskId);
    }

    /**
     * Ø§Ù Ø²ÙˆØ¯Ù†/Ø­Ø°Ù  Ø§Ø² Ø¹Ù„Ø§Ù‚Ù‡â€ŒÙ…Ù†Ø¯ÛŒâ€ŒÙ‡Ø§
     */
    public function toggleFavorite(int $taskId, int $userId): array
    {
        try {
            $isCurrentlyFavorite = $this->taskModel->isTaskFavorited($taskId, $userId);

            $this->db->beginTransaction();

            if ($isCurrentlyFavorite) {
                // Ø­Ø°Ù  Ø§Ø² Ø¹Ù„Ø§Ù‚Ù‡â€ŒÙ…Ù†Ø¯ÛŒâ€ŒÙ‡Ø§
                $success = $this->taskModel->removeFromFavorites($taskId, $userId);
                $message = 'Ø§Ø² Ø¹Ù„Ø§Ù‚Ù‡â€ŒÙ…Ù†Ø¯ÛŒâ€ŒÙ‡Ø§ Ø­Ø°Ù  Ø´Ø¯.';
                $isFavorite = false;
            } else {
                // Ø§Ø¶Ø§Ù Ù‡ Ø¨Ù‡ Ø¹Ù„Ø§Ù‚Ù‡â€ŒÙ…Ù†Ø¯ÛŒâ€ŒÙ‡Ø§
                $success = $this->taskModel->addToFavorites($taskId, $userId);
                $message = 'Ø¨Ù‡ Ø¹Ù„Ø§Ù‚Ù‡â€ŒÙ…Ù†Ø¯ÛŒâ€ŒÙ‡Ø§ Ø§Ø¶Ø§Ù Ù‡ Ø´Ø¯.';
                $isFavorite = true;
            }

            if ($success) {
                $this->db->commit();
                return [
                    'success' => true,
                    'message' => $message,
                    'is_favorite' => $isFavorite
                ];
            } else {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø¹Ù…Ù„ÛŒØ§Øª.'];
            }

        } catch (\Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Ø®Ø·Ø§ Ø¯Ø± Ø¹Ù…Ù„ÛŒØ§Øª.'];
        }
    }

    /**
     * Ø¯Ø±ÛŒØ§Ù Øª Ø¢Ù…Ø§Ø± ØªÙ ØµÛŒÙ„ÛŒ ÛŒÚ© ØªØ³Ú©
     */
    public function getTaskAnalytics(int $taskId, int $days = 30): array
    {
        $analytics = $this->analyticsModel->getTaskAnalytics($taskId);

        // ØªÙˆØ²ÛŒØ¹ Ø§Ù…ØªÛŒØ§Ø²Ù‡Ø§
        $ratings = $this->ratingModel->getTaskRatings($taskId, 100, 0);

        return [
            'overall' => $analytics['overall'],
            'daily' => $analytics['daily'],
            'ratings' => $ratings,
        ];
    }

    /**
     * Auto-approve submissions Ú©Ù‡ Ù…Ø¯Øª Ø²ÛŒØ§Ø¯ÛŒ Ø¨Ø±Ø±Ø³ÛŒ Ù†Ø´Ø¯Ù‡â€ŒØ§Ù†Ø¯
     */
    public function autoApproveOldSubmissions(): int
    {
        $hours = (int) $this->settingService->get('custom_task_auto_approve_hours', 48);

        $submissions = $this->submissionModel->getOldSubmissionsForAutoApproval($hours);

        $approved = 0;
        foreach ($submissions as $sub) {
            // Directly use the fetched object, eliminating N+1 secondary query.
            $result = $this->approveSubmission($sub);
            if ($result['success']) {
                $approved++;

                // Ù†ÙˆØªÛŒÙ ÛŒÚ©ÛŒØ´Ù† ØªØ§ÛŒÛŒØ¯ Ø®ÙˆØ¯Ú©Ø§Ø±
                $this->notificationService->send(
                    $sub->worker_id,
                    'auto_approved',
                    'Ù…Ø¯Ø±Ú© Ø´Ù…Ø§ Ø¨Ù‡ ØµÙˆØ±Øª Ø®ÙˆØ¯Ú©Ø§Ø± ØªØ§ÛŒÛŒØ¯ Ø´Ø¯',
                    "Ù…Ø¯Ø±Ú© Ø´Ù…Ø§ Ø¨Ø±Ø§ÛŒ ÙˆØ¸ÛŒÙ Ù‡ Â«{$sub->task_title}Â» Ø¨Ù‡ Ø¯Ù„ÛŒÙ„ Ø¹Ø¯Ù… Ø¨Ø±Ø±Ø³ÛŒ ØªÙˆØ³Ø· ØªØ¨Ù„ÛŒØºâ€ŒØ¯Ù‡Ù†Ø¯Ù‡ØŒ Ø®ÙˆØ¯Ú©Ø§Ø± ØªØ§ÛŒÛŒØ¯ Ùˆ Ù¾Ø§Ø¯Ø§Ø´ Ù¾Ø±Ø¯Ø§Ø®Øª Ø´Ø¯.",
                    [
                        'submission_id' => $sub->id,
                        'task_id' => $sub->task_id
                    ]
                );
            }
        }

        return $approved;
    }

    /**
     * Ù…Ù†Ù‚Ø¶ÛŒ Ú©Ø±Ø¯Ù† submission Ù‡Ø§ÛŒ deadline Ú¯Ø°Ø´ØªÙ‡
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

                // Ø¢Ø²Ø§Ø¯Ø³Ø§Ø²ÛŒ Ø¸Ø±ÙÛŒØª Ø¯Ø± Ù…Ø¯Ù„ Ù…ØªÙ…Ø±Ú©Ø² Ads
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

}
