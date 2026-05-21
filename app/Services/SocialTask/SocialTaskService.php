<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Policies\RateLimitPolicy;
use App\Services\FinancialEscrowService;
use App\Services\StateMachineService;
use App\Services\WebSocketService;
use App\Models\SocialTaskModel;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;
use App\Services\Shared\ReferralService;
use App\Services\SocialTask\CameraVerificationService;
use App\Services\User\UserService;
use App\Services\OutboxService;
use App\Validators\Requests\ExecuteSocialTaskRequest;

/**
 * SocialTaskService
 *
 * هماهنگ‌کننده اصلی ماژول SocialTask.
 */
class SocialTaskService extends \App\Services\BaseService
{
    // تسک‌های یوتیوب جدا هستند
    private const EXCLUDED_PLATFORMS_FROM_SOCIAL = ['youtube'];

    // زمان انتظار (ثانیه) برای rate limit per task_type
    private const DEFAULT_TASK_EXPECTED_TIME = [
        'follow'       => 45,
        'like'         => 20,
        'comment'      => 90,
        'share'        => 30,
        'retweet'      => 25,
        'join_channel' => 30,
        'join_group'   => 30,
    ];

    public function __construct(
        private SocialTaskModel $model,
        private SocialTaskScoringService $scoring,
        private TrustScoreService $trust,
        private SilentAntiFraudService $antiFraud,
        private WalletServiceInterface $wallet,
        private NotificationServiceInterface $notification,
        private RateLimitPolicy $rateLimiter,
        protected LoggerInterface $logger,
        private FinancialEscrowService $escrow,
        private StateMachineService $stateMachine,
        private WebSocketService $webSocket,
        private \App\Services\Shared\RatingService $ratingService,
        private ?ReferralService $referralService = null,
        private UserService $userService,
        private SettingService $settingService,
        private ?CameraVerificationService $cameraVerification = null,
        private ?\App\Services\AntiFraud\FraudGuardService $fraudGuard = null,
        private ?OutboxService $outbox = null
    ) {
        // 🛡️ H11 Fix: Pass logger to parent constructor instead of using uninitialized $this->logger
        parent::__construct($logger);
    }

    /**
     * لیست تسک‌های فعال برای کاربر با اعمال فیلتر نامحسوس
     */
    public function getTasksForExecutor(int $userId, array $filters = [], int $limit = 20): array
    {
        $restriction = $this->antiFraud->getRestrictionLevel($userId);
        $effectiveLimit = $this->antiFraud->filterTaskCount($userId, $limit);

        // Construction of Clean Filter Map for centralized Filterable Trait processing
        $mappedFilters = [];

        if (!empty($filters['platform'])) {
            $mappedFilters['platform'] = $filters['platform'];
        }
        
        if (!empty($filters['task_type'])) {
            $mappedFilters['task_type'] = $filters['task_type'];
        }

        if (!empty($filters['min_reward'])) {
            $mappedFilters['min_reward'] = (float)$filters['min_reward'];
        }

        if (!empty($filters['max_reward'])) {
            $mappedFilters['max_reward'] = (float)$filters['max_reward'];
        }

        $medianReward = $this->model->getMedianReward();
        if (empty($filters['is_mobile'])) {
            $mappedFilters['budget_cap'] = $medianReward;
        }

        if (!empty($filters['search'])) {
            $mappedFilters['search'] = (string)$filters['search'];
        }

        $orderBy = match ($filters['sort'] ?? 'random') {
            'price_desc' => 'sa.price_per_task DESC',
            'price_asc'  => 'sa.price_per_task ASC',
            'newest'     => 'sa.created_at DESC',
            default      => 'RAND()',
        };

        // High level secure dispatch to overhauled Model method
        $tasks = $this->model->getActiveAds(
            $userId, 
            $mappedFilters, 
            $orderBy, 
            $effectiveLimit,
            self::EXCLUDED_PLATFORMS_FROM_SOCIAL
        );

        foreach ($tasks as &$task) {
            $task->display_reward = $this->antiFraud->adjustedReward($userId, (float)$task->price_per_task);
            $task->trust_display = $this->trust->get($userId);
        }

        return [
            'tasks' => $tasks,
            'restriction_level' => $restriction['level'],
            'trust_score' => $this->trust->get($userId),
        ];
    }

    public function adminRejectAd(int $adminId, int $adId, string $reason): array
    {
        try {
            $ad = $this->model->getAdById($adId);
            if (!$ad) return ['success' => false, 'message' => 'تبلیغ یافت نشد'];

            if (in_array($ad->status, ['completed', 'cancelled', 'rejected'], true)) {
                return ['success' => false, 'message' => 'این تبلیغ قابل رد شدن نیست'];
            }

            $this->model->updateAdStatus($adId, 'rejected', [
                'reject_reason' => $reason,
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s')
            ]);

            return ['success' => true, 'message' => 'تبلیغ با موفقیت رد شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطا در رد تبلیغ: ' . $e->getMessage()];
        }
    }

    public function adminCancelAd(int $adminId, int $adId): array
    {
        try {
            $this->model->beginTransaction();
            $ad = $this->model->getAdById($adId, true);

            if (!$ad) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'تبلیغ یافت نشد'];
            }

            if (in_array($ad->status, ['completed', 'cancelled'], true)) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'این تبلیغ قابل لغو نیست'];
            }

            $refund   = (float)($ad->remaining_budget ?? 0);
            // MED-23: Dynamically check ad currency to accurately handle both IRT and USDT campaign budgets
            $currency = (string)($ad->currency ?? 'irt');

            if ($refund > 0) {
                $idempotencyKey = "social_ad_cancel_refund_{$adId}";
                $walletResult = $this->wallet->deposit((int)$ad->user_id, (string)$refund, $currency, [
                    'type' => 'social_ad_refund',
                    'description' => "Refund for cancelled social ad #{$adId}",
                    'idempotency_key' => $idempotencyKey,
                    'gateway' => 'social_ad_refund',
                    'gateway_transaction_id' => 'refund_' . $adId,
                    'ref_id' => $adId,
                    'ref_type' => 'social_ad',
                ]);

                if (empty($walletResult['success'])) {
                    $this->model->rollBack();
                    return ['success' => false, 'message' => $walletResult['message'] ?? 'خطا در بازگشت وجه'];
                }
            }

            $this->model->updateAdStatus($adId, 'cancelled');
            $this->model->commit();

            return ['success' => true, 'message' => 'تبلیغ لغو شد', 'refund' => $refund, 'currency' => $currency];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در لغو تبلیغ: ' . $e->getMessage()];
        }
    }

    public function adminFlagExecution(int $adminId, int $executionId, string $note = ''): array
    {
        try {
            $exec = $this->model->getExecutionById($executionId);
            if (!$exec) return ['success' => false, 'message' => 'اجرا یافت نشد'];

            if (in_array($exec->status, ['expired', 'cancelled'], true)) {
                return ['success' => false, 'message' => 'این اجرا قابل علامت‌گذاری نیست'];
            }

            $this->model->flagExecution($executionId, $note);
            return ['success' => true, 'message' => 'اجرا برای بررسی علامت‌گذاری شد'];
        } catch (\Throwable $e) {
            // LOW-12: Log exceptions inside catch blocks to preserve diagnostic records
            $this->logger->error('social.execution.flagging_failed', [
                'execution_id' => $executionId,
                'admin_id'     => $adminId,
                'error'        => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در علامت‌گذاری اجرا'];
        }
    }

    public function adminOverrideExecution(int $adminId, int $executionId, string $decision, string $reason): array
    {
        try {
            if (!in_array($decision, ['approved', 'soft_approved', 'rejected'], true)) {
                return ['success' => false, 'message' => 'تصمیم معتبر نیست'];
            }

            $reason = trim($reason);
            if ($reason === '') return ['success' => false, 'message' => 'دلیل override الزامی است'];

            $this->model->beginTransaction();
            $exec = $this->model->getExecutionById($executionId, true);

            if (!$exec) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'اجرا یافت نشد'];
            }

            $this->model->updateExecutionStatus($executionId, $decision, [
                'decision' => $decision,
                'override_reason' => $reason,
                'reviewed_by' => $adminId,
                'reviewed_at' => date('Y-m-d H:i:s')
            ]);

            $this->model->commit();
            return ['success' => true, 'message' => 'تصمیم با موفقیت override شد', 'old_decision' => $exec->decision ?? null, 'new_decision' => $decision];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            $this->logger->error('social.admin_override_execution_failed', [
                'adS_id' => $adminId,
                'execution_id' => $executionId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در override تصمیم'];
        }
    }

    public function adminAdjustTrust(int $adminId, int $userId, float $delta, string $reason): array
    {
        try {
            $reason = trim($reason);
            if ($reason === '') return ['success' => false, 'message' => 'دلیل الزامی است'];
            if ($delta == 0.0) return ['success' => false, 'message' => 'مقدار تغییر نمی‌تواند صفر باشد'];

            $this->model->beginTransaction();
            $row = $this->model->getUserTrust($userId, true);

            $oldTrust = $row ? (float)$row->trust_score : 50.0;
            $newTrust = max(0.0, min(100.0, $oldTrust + $delta));

            $this->model->upsertUserTrust($userId, $newTrust);
            $this->model->recordTrustAdjustment([
                'user_id' => $userId,
                'admin_id' => $adminId,
                'delta' => $delta,
                'old_trust' => $oldTrust,
                'new_trust' => $newTrust,
                'reason' => $reason
            ]);

            $this->model->commit();
            return ['success' => true, 'message' => 'امتیاز اعتماد با موفقیت تغییر کرد', 'old_trust' => $oldTrust, 'new_trust' => $newTrust];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در تغییر امتیاز اعتماد'];
        }
    }

    
    public function recordBehaviorSignals(int $executionId, int $userId, array $signals): bool
    {
        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->executor_id !== $userId) return false;

        $behaviorData = $this->model->getBehaviorData($executionId);
        $prevData = $behaviorData ? json_decode($behaviorData, true) ?: [] : [];

        $merged = $this->mergeBehaviorSignals($prevData, $signals);
        $this->model->updateExecutionBehavior($executionId, json_encode($merged, JSON_UNESCAPED_UNICODE));

        return true;
    }

        public function startExecution(int $userId, int $adId, array $context = []): array
    {
        // 🛡️ گیت ضدتقلب شبکه‌های اجتماعی (Silent anti-fraud, IP check)
        if ($this->fraudGuard) {
            $risk = $this->fraudGuard->checkAction($userId, 'task.social', [
                'task_id'    => $adId,
                'action'     => 'start',
                'ip'         => $context['ip'] ?? '',
                'user_agent' => $context['user_agent'] ?? ''
            ]);

            if (!$risk['allowed']) {
                $this->logger->warning('social.task_start_blocked_by_fraud_guard', [
                    'user_id' => $userId,
                    'ad_id'   => $adId,
                    'reason'  => $risk['reason']
                ]);
                return ['success' => false, 'message' => 'امکان شروع تسک به دلیل محدودیت رفتارهای غیرمجاز مسدود شد.'];
            }
        }

        try {
            $this->model->beginTransaction();
            // قفل کردن ردیف با FOR UPDATE برای جلوگیری از Race Condition
            $ad = $this->model->getAdById($adId, true);

            if (!$ad || $ad->status !== 'active' || $ad->remaining_count <= 0) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'تسک موجود نیست یا ظرفیت تکمیل شده'];
            }

            // چک کردن اینکه قبلا انجام نشده باشد
            $existing = $this->model->getExecutionWithAd($adId, $userId);
            if ($existing && !in_array($existing->status, ['expired', 'cancelled', 'rejected'], true)) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'شما قبلاً این تسک را انجام داده‌اید یا در حال انجام آن هستید'];
            }

            // M36 Fix: جایگزینی فراخوانی ریت‌لیمیتر قدیمی با سیستم جدید و استاندارد سیاست محدودیت
            if (!$this->rateLimiter->check('task_submit', $userId)) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'محدودیت تعداد تسک در ساعت'];
            }

            $expectedTimeMap = $this->settingService->get('social_task_expected_times', self::DEFAULT_TASK_EXPECTED_TIME);
            $expectedTime = $expectedTimeMap[$ad->task_type] ?? 60;

            if ($this->model->decrementAdSlots($adId) < 1) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'ظرفیت تکمیل شده'];
            }

            $execId = $this->model->createExecution([
                'ad_id' => $adId,
                'executor_id' => $userId,
                'ip_address' => $context['ip'] ?? '',
                'user_agent' => $context['user_agent'] ?? '',
                'expected_time' => $expectedTime
            ]);

            $this->model->commit();
            return ['success' => true, 'execution_id' => $execId, 'expected_time' => $expectedTime, 'target_url' => $ad->target_url, 'task_type' => $ad->task_type];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            $this->logger->error('social.start_execution_failed', [
                'user_id' => $userId,
                'ad_id' => $adId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
        }
    }

    /**
     * Section 8.2 — Idempotent shim. Repeated submits for the same
     * (userId, executionId) return the same cached result.
     */
    public function submitExecution(int $userId, int $executionId, array $payload = []): array
    {
        return $this->idempotent(
            'social_task.submit',
            $userId,
            ['execution_id' => $executionId],
            fn() => $this->submitExecutionInternal($userId, $executionId, $payload)
        );
    }

    private function submitExecutionInternal(int $userId, int $executionId, array $payload = []): array
    {
        // اعتبارسنجی از طریق Request object — همه rules در یک جا تعریف شده‌اند
        if ($userId <= 0 || $executionId <= 0) {
            return ['success' => false, 'message' => 'شناسه کاربر یا اجرا نامعتبر است'];
        }

        $request = new ExecuteSocialTaskRequest(array_merge($payload, ['execution_id' => $executionId]));
        if (!$request->validate()) {
            $firstError = array_values($request->errors())[0] ?? 'داده‌های ارسال تسک نامعتبر است';
            return ['success' => false, 'message' => is_array($firstError) ? ($firstError[0] ?? 'نامعتبر') : $firstError];
        }

        if (!$request->hasProof()) {
            return ['success' => false, 'message' => 'مدرک انجام تسک الزامی است'];
        }

        // payload را با مقادیر trim‌شده و validated جایگزین می‌کنیم
        $payload = array_merge($payload, $request->validated());
        $payload['proof_url']  = trim((string)($payload['proof_url']  ?? ''));
        $payload['proof_text'] = trim((string)($payload['proof_text'] ?? ''));

        try {
            $this->model->beginTransaction();
            $exec = $this->model->getExecutionWithAd($executionId, $userId, true);

            if (!$exec) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'رکورد اجرا یافت نشد'];
            }

            if ($exec->status !== 'pending') {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'وضعیت اجرا برای ارسال معتبر نیست'];
            }

            $proofUrl  = $payload['proof_url']  ?? '';
            $proofText = $payload['proof_text'] ?? '';

            // 🛡️ گیت متمرکز ضدتقلب (شامل بررسی کپی بودن ویدئو و الگوهای رفتاری سیستمی)
            if ($this->fraudGuard) {
                $risk = $this->fraudGuard->checkAction($userId, 'task.social', [
                    'task_id'          => (int)($exec->ad_id ?? 0),
                    'execution_id'     => $executionId,
                    'video_hash'       => $payload['video_hash'] ?? null,
                    'behavior_signals' => $payload['behavior_signals'] ?? []
                ]);

                if (!$risk['allowed']) {
                    $this->model->rollBack();
                    $this->logger->warning('social.task_submission_blocked_by_fraud_guard', [
                        'user_id'      => $userId,
                        'execution_id' => $executionId,
                        'reason'       => $risk['reason']
                    ]);
                    return ['success' => false, 'message' => 'ثبت نتیجه تسک به دلیل هشدارهای سیستمی مسدود شد.'];
                }
            }

            $score = $this->antiFraud->scoreExecution($exec, $payload);
            
            // Capture Fraud in Real-Time via CameraVerification if signals are suspicious
            $behaviorSignals = (array)($payload['behavior_signals'] ?? []);
            $requireCamera = false;
            try {
                if ($this->cameraVerification && $this->cameraVerification->isRequired((int)$executionId, (float)($score['task_score'] ?? 0), $behaviorSignals)) {
                    $requireCamera = true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('camera_verification.check_failed_fallback_allowed', [
                    'execution_id' => $executionId,
                    'error' => $e->getMessage()
                ]);
            }

            if ($requireCamera) {
                try {
                    $this->cameraVerification->createRequest((int)$executionId, $userId);
                    $this->model->updateExecutionStatus($executionId, 'pending_camera_verification', [
                        'anti_fraud_score' => (float)($score['task_score'] ?? 0),
                        'proof_url'        => $proofUrl !== '' ? $proofUrl : null,
                        'proof_text'       => $proofText !== '' ? $proofText : null,
                    ]);
                    $this->model->commit();

                    return [
                        'success' => true,
                        'status'  => 'pending_camera_verification',
                        'message' => 'تسک شما مشکوک تشخیص داده شد. لطفاً با استفاده از دوربین هویت تصویری خود را تأیید کنید تا پاداش آزاد شود.',
                        'score'   => $score['task_score'] ?? 0,
                    ];
                } catch (\Throwable $e) {
                    $this->logger->error('camera_verification.create_request_failed_fallback_bypass', [
                        'execution_id' => $executionId,
                        'error' => $e->getMessage()
                    ]);
                    // Fallback to normal decision flow because camera verification is temporarily down
                }
            }

            $decision = $this->antiFraud->decisionFromScore($score);

            $finalStatus = ($decision['decision'] ?? '') === 'reject' ? 'rejected' : 'approved';
            $rewardPaid = 0;
            $rewardAmount = 0.0;

            if (!empty($decision['pay_reward'])) {
                $rewardAmount = (float)$this->antiFraud->adjustedReward($userId, (float)$exec->price_per_task);
                // Dynamically resolve execution currency context
                $currency = (string)($exec->currency ?? 'irt');

                if ($rewardAmount > 0) {
                    $pay = $this->wallet->deposit($userId, (string)$rewardAmount, $currency, [
                        'type' => 'social_task_reward',
                        'execution_id' => $executionId,
                        'ad_id' => (int)$exec->ad_id,
                        'task_type' => $exec->task_type ?? null,
                        'decision' => $decision['decision'] ?? null,
                        'risk_score' => $score['score'] ?? null,
                    ]);

                    if (empty($pay['success'])) {
                        $this->model->rollBack();
                        return ['success' => false, 'message' => $pay['message'] ?? 'خطا در پرداخت پاداش'];
                    }
                    $rewardPaid = 1;
                    
                    // بررسی ارجاع دهنده (معرف) و پرداخت کمیسیون
                    // HIGH-07: Fully decouple direct DB layer dependencies by consuming injected UserService
                    $userRecord = $this->userService->findById($userId);
                    if ($userRecord && !empty($userRecord->referred_by)) {
                        if ($this->referralService) {
                            $this->referralService->processCommission((int)$userRecord->referred_by, $rewardAmount, $currency, [
                                'action' => 'social_task_reward',
                                'executor_id' => $userId,
                                'execution_id' => $executionId
                            ]);
                        }
                    }
                }
            }

            $this->model->updateExecutionStatus($executionId, $finalStatus, [
                'proof_url' => $proofUrl !== '' ? $proofUrl : null,
                'proof_text' => $proofText !== '' ? $proofText : null,
                'anti_fraud_score' => (float)($score['score'] ?? 0),
                'reward_paid' => $rewardPaid,
                'reward_amount' => $rewardAmount
            ]);

            $this->recordExecutionOutbox($executionId, $userId, $finalStatus, $rewardPaid, $rewardAmount, $currency ?? (string)($exec->currency ?? 'irt'), $score, $decision);

            $this->model->commit();
            
            return [
                'success' => true, 
                'message' => 'ارسال با موفقیت انجام شد', 
                'status' => $finalStatus,
                'score' => $score['score'] ?? 0
            ];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            $this->logger->error('social.submit_execution_failed', [
                'user_id' => $userId,
                'execution_id' => $executionId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
        }
    }

    public function advertiserApprove(int $advertiserId, int $executionId): array
    {
        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->user_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->model->updateExecutionStatus($executionId, 'approved');
        return ['success' => true, 'message' => 'اجرا تأیید شد'];
    }

    public function advertiserReject(int $advertiserId, int $executionId, string $reason): array
    {
        if (empty(trim($reason))) return ['success' => false, 'message' => 'دلیل رد الزامی است'];

        $exec = $this->model->getExecutionById($executionId);
        if (!$exec || (int)$exec->user_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->model->updateExecutionStatus($executionId, 'rejected', ['reject_reason' => $reason]);
        $this->trust->penalizeRejection((int)$exec->executor_id, $executionId);

        return ['success' => true, 'message' => 'اجرا رد شد'];
    }

    public function getAdById(int $adId): ?object
    {
        return $this->model->getAdById($adId);
    }

    public function getUserAccounts(int $userId): array
    {
        $sql = "SELECT * FROM user_social_accounts WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC";
        return $this->model->getDb()->query($sql, [$userId])->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function addAccount(int $userId, string $platform, string $username, string $accessToken = ''): array
    {
        try {
            $this->model->beginTransaction();

            $existsSql = "SELECT COUNT(*) FROM user_social_accounts WHERE platform = ? AND username = ? AND deleted_at IS NULL";
            $count = (int)$this->model->getDb()->fetchColumn($existsSql, [$platform, $username]);
            if ($count > 0) {
                $this->model->rollBack();
                return ['success' => false, 'message' => 'این حساب کاربری قبلاً ثبت شده است'];
            }

            $sql = "INSERT INTO user_social_accounts (user_id, platform, username, profile_url, follower_count, following_count, post_count, engagement_rate, account_age_months, status, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, 0, 0, 0, 0.0, 0, 'pending', NOW(), NOW())";
            
            $profileUrl = "https://{$platform}.com/{$username}";
            $this->model->getDb()->query($sql, [$userId, $platform, $username, $profileUrl]);

            $accountId = (int)$this->model->getDb()->lastInsertId();
            $this->model->commit();

            return ['success' => true, 'id' => $accountId, 'message' => 'حساب با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            $this->model->rollBack();
            return ['success' => false, 'message' => 'خطا در ثبت حساب: ' . $e->getMessage()];
        }
    }

    public function getExecutorStats(int $userId): object
    {
        return $this->model->getExecutorStats($userId) ?: (object)['total' => 0, 'approved' => 0, 'soft_approved' => 0, 'rejected' => 0, 'avg_score' => 0, 'success_rate' => 0];
    }

    public function getAdvertiserAdStats(int $advertiserId, int $adId): ?object
    {
        return $this->model->getAdvertiserAdStats($adId, $advertiserId);
    }

    public function getExecutorHistory(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getExecutorHistory($userId, $limit, $offset);
    }



    private function recordExecutionOutbox(
        int $executionId,
        int $userId,
        string $status,
        int $rewardPaid,
        float $rewardAmount,
        string $currency,
        array $score,
        array $decision
    ): void {
        if (!$this->outbox) {
            return;
        }

        $this->outbox->record('social_task_execution', (string)$executionId, 'social_task.execution.completed', [
            'execution_id' => $executionId,
            'user_id' => $userId,
            'status' => $status,
            'reward_paid' => $rewardPaid,
            'reward_amount' => $rewardAmount,
            'currency' => $currency,
            'score' => $score,
            'decision' => $decision,
        ]);

        if ($status === 'approved' && $rewardPaid === 1) {
            $this->outbox->record('social_task_execution', (string)$executionId, 'score.task_completed', [
                'job' => \App\Jobs\UpdateFraudScoreJob::class,
                'data' => [
                    'user_id' => $userId,
                    'delta' => 1.0,
                    'domain' => 'task',
                    'source' => 'social_task_execution',
                    'meta' => [
                        'execution_id' => $executionId,
                        'task_score' => $score['task_score'] ?? ($score['score'] ?? null),
                        'reward_amount' => $rewardAmount,
                        'currency' => $currency,
                    ],
                ],
            ]);
        }
    }

    private function sanitizeSearch(string $str): string
    {
        return preg_replace('/[^\p{L}\p{N}\s]/u', '', $str) ?: '';
    }

    private function mergeBehaviorSignals(array $prev, array $new): array
    {
        foreach ($new as $k => $v) {
            if (is_numeric($v)) {
                $prev[$k] = ($prev[$k] ?? 0) + $v;
            } else {
                $prev[$k] = $v;
            }
        }
        return $prev;
    }

    /**
     * گزارش تخلف تسک شبکه اجتماعی (سوشیال تسک)
     */
    public function reportTask(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        $ad = $this->model->getAdById($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        try {
            $ok = $this->ratingService->report([
                'reporter_id' => $reporterId,
                'ref_type' => 'social_task',
                'ref_id' => $adId,
                'reason' => $reason,
                'description' => $description
            ]);

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت گزارش'];
            }

            return ['success' => true, 'message' => 'گزارش با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }

    /**
     * امتیازدهی به تسک شبکه اجتماعی (سوشیال تسک)
     */
    public function rateTask(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        $ad = $this->model->getAdById($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            $ok = $this->ratingService->rate(
                $raterId,
                (int)$ad->user_id,
                'social_task',
                $adId,
                $stars,
                $comment
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
    public function getExecutionForUser(int $executionId, int $userId): ?object
    {
        return $this->model->getExecutionWithAd($executionId, $userId);
    }

    public function getExecutionForAdvertiser(int $userId, int $executionId): ?object
    {
        return $this->model->getExecutionWithAdForAdvertiser($executionId, $userId);
    }

    public function getMyAds(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getByAdvertiser($userId, $limit, $offset);
    }

    public function getAdExecutions(int $adId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getExecutionsByAd($adId, $limit, $offset);
    }

    public function toggleAdStatus(int $userId, int $adId, string $status): array
    {
        $valid = ['active', 'paused', 'cancelled'];
        if (!in_array($status, $valid)) {
            return ['success' => false, 'message' => 'وضعیت درخواستی معتبر نیست'];
        }

        $ad = $this->model->getAdById($adId);
        if (!$ad || (int)$ad->user_id !== $userId) {
            return ['success' => false, 'message' => 'آگهی یافت نشد یا دسترسی غیرمجاز'];
        }

        if ($status === 'cancelled') {
            return $this->adminCancelAd($userId, $adId); // Reuses advanced atomic refund logic!
        }

        $this->model->updateAdStatus($adId, $status);
        return ['success' => true, 'message' => "وضعیت آگهی به {$status} تغییر یافت"];
    }

    public function getAdvertiserSummary(int $userId): array
    {
        $stats = $this->model->getWeeklyExecutionStats($userId); // Simplification for dashboard
        return [
            'total_executions' => $stats->total ?? 0,
            'approved_count' => $stats->good_tasks ?? 0,
            'avg_rating' => 4.5 // Placeholder/Simulated for this summary level
        ];
    }

    public function searchSocialTasks(array $filters, int $limit, int $offset): array
    {
        // Using the native DB builder via the model, adhering to query standards.
        $query = $this->model->getDb()->table('social_ads')
            ->select('id', 'title', 'description', 'platform', 'task_type', 'reward', 'status', 'created_at')
            ->where('status', '=', 'active');

        if (!empty($filters['q'])) {
            $like = '%' . $this->sanitizeSearch((string)$filters['q']) . '%';
            $query->where(function($sub) use ($like) {
                $sub->where('title', 'LIKE', $like)->orWhere('description', 'LIKE', $like);
            });
        }

        if (!empty($filters['platform'])) {
            $query->where('platform', '=', e($filters['platform'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($filters['task_type'])) {
            $query->where('task_type', '=', e($filters['task_type'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($filters['min_reward'])) {
            $query->where('reward', '>=', (float)$filters['min_reward']);
        }
        if (!empty($filters['max_reward'])) {
            $query->where('reward', '<=', (float)$filters['max_reward']);
        }

        // Calculate Order By sequence
        $sort = $filters['sort'] ?? 'newest';
        [$sortCol, $sortDir] = match ($sort) {
            'oldest' => ['created_at', 'ASC'],
            'reward_high' => ['reward', 'DESC'],
            'reward_low' => ['reward', 'ASC'],
            default => ['created_at', 'DESC'],
        };

        return [
            'total' => $query->count(), // Atomic query counting
            'items' => (clone $query)->orderBy($sortCol, $sortDir)
                                     ->limit($limit)
                                     ->offset($offset)
                                     ->get() ?? []
        ];
    }
}

