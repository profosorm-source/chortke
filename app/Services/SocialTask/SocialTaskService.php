<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Policies\RateLimitPolicy;
use App\Domain\Financial\Services\FinancialEscrowService;
use App\Services\StateMachineService;
use App\Services\WebSocketService;
use App\Models\SocialTaskModel;
use App\Services\Gamification\TrustService;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use App\Services\Shared\ReferralService;
use App\Services\SocialTask\CameraVerificationService;
use App\Services\User\UserService;
use App\Services\OutboxService;
use App\Services\Interaction\RatingService as InteractionRatingService;
use App\Models\SocialTaskAnalyticsModel;
use App\Services\AntiFraud\TaskExecutionEvaluatorService;
use App\Validators\Requests\ExecuteSocialTaskRequest;

/**
 * SocialTaskService
 *
 * هماهنگ‌کننده اصلی ماژول SocialTask.
 */
class SocialTaskService
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

    private \Core\EventDispatcher $eventDispatcher;
    private \App\Contracts\LoggerInterface $logger;
    private SocialTaskModel $model;
    private TrustService $trust;
    private SilentAntiFraudService $antiFraud;
    private UserService $userService;
    private ?CameraVerificationService $cameraVerification;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \App\Contracts\LoggerInterface $logger,
        SocialTaskModel $model,
        TrustService $trust,
        SilentAntiFraudService $antiFraud,
        UserService $userService,
        ?CameraVerificationService $cameraVerification = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->model = $model;
        $this->trust = $trust;
        $this->antiFraud = $antiFraud;
        $this->userService = $userService;
        $this->cameraVerification = $cameraVerification;
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

        // ✅ FIX N+1 QUERY: Fetch trust score once, not per task
        $userObj = $this->userService->findById($userId);
        $userTrustScore = $userObj ? $this->trust->getTrustScore($userObj, ModuleContext::SOCIAL_TASKS) : 50.0;
        
        foreach ($tasks as &$task) {
            $task->display_reward = $this->antiFraud->adjustedReward($userId, (float)$task->price_per_task);
            $task->trust_display = $userTrustScore; // Reuse cached value, not N queries
        }

        return [
            'tasks' => $tasks,
            'restriction_level' => $restriction['level'],
            'trust_score' => $userTrustScore, // Use same cached value
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
            // [SAGA REFACTOR] Removed transaction wrapper to allow distributed execution
            return (function() use ($adId, $adminId) {
                
                $saga = $this->context->getContainer()->make(\App\Services\SagaOrchestrator::class);
                $skip = false;
                $earlyResult = null;
                $refund = 0;
                $currency = 'irt';

                $saga->addStep(
                    'validate_and_refund',
                    function () use ($adId, &$skip, &$earlyResult, &$refund, &$currency) {
                        $ad = $this->model->getAdById($adId, true);
            
                        if (!$ad) {
                            $skip = true;
                            $earlyResult = ['success' => false, 'message' => 'تبلیغ یافت نشد'];
                            return;
                        }
            
                        if (in_array($ad->status, ['completed', 'cancelled'], true)) {
                            $skip = true;
                            $earlyResult = ['success' => false, 'message' => 'این تبلیغ قابل لغو نیست'];
                            return;
                        }
            
                        $refund   = (float)($ad->remaining_budget ?? 0);
                        // MED-23: Dynamically check ad currency to accurately handle both IRT and USDT campaign budgets
                        $currency = (string)($ad->currency ?? 'irt');
            
                        if ($refund > 0) {
                            $this->eventDispatcher->dispatch('social_task.ad_cancelled_refund', [
                                'ad_id' => $adId,
                                'user_id' => (int)$ad->user_id,
                                'refund' => $refund,
                                'currency' => $currency
                            ]);
                        }
                    },
                    function (\Throwable $e) use ($adId) {
                        $this->logger->warning('saga.compensating.social_ad_refund', ['ad_id' => $adId]);
                        if ($refund > 0) {
                            // Reverse the refund via reverse event or direct wallet
                            $this->eventDispatcher->dispatch('social_task.ad_cancelled_refund_reverse', [
                                'ad_id' => $adId,
                                'user_id' => (int)$ad->user_id,
                                'refund' => $refund,
                                'currency' => $currency
                            ]);
                        }
                    }
                )->addStep(
                    'update_status',
                    function () use ($adId, &$skip) {
                        if ($skip) return;
                        $this->model->updateAdStatus($adId, 'cancelled');
                    },
                    function (\Throwable $e) use ($adId) {
                        $this->logger->warning('saga.compensating.social_ad_status', ['ad_id' => $adId]);
                        $this->model->updateAdStatus($adId, 'active'); // Revert to active
                    }
                );

                $saga->execute();

                if ($skip) {
                    return $earlyResult;
                }
    
                return ['success' => true, 'message' => 'تبلیغ لغو شد', 'refund' => $refund, 'currency' => $currency];
            });
        } catch (\Throwable $e) {
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

            $exec = $this->model->getExecutionById($executionId, false); // No FOR UPDATE needed without transaction

                if (!$exec) {
                    return ['success' => false, 'message' => 'اجرا یافت نشد'];
                }

                $this->model->updateExecutionStatus($executionId, $decision, [
                    'decision' => $decision,
                    'override_reason' => $reason,
                    'overridden_by' => $adminId,
                    'overridden_at' => date('Y-m-d H:i:s')
                ]);

                if (in_array($decision, ['approved', 'soft_approved'], true)) {
                    $ad = $this->model->getAdById((int)$exec->ad_id);
                    if ($ad) {
                        $payout = (float)$ad->payout_amount;
                        $currency = $ad->currency ?? 'irt';
                        $this->eventDispatcher->dispatchAsync('social_task.reward_approved', [
                            'user_id' => (int)$exec->executor_id,
                            'execution_id' => $executionId,
                            'ad_id' => $ad->id,
                            'task_type' => $exec->task_type ?? null,
                            'decision' => $decision,
                            'reward_amount' => $payout,
                            'currency' => $currency
                        ]);
                    }
                }

                return ['success' => true, 'message' => 'تصمیم با موفقیت override شد', 'old_decision' => $exec->decision ?? null, 'new_decision' => $decision];
        } catch (\Throwable $e) {
            $this->logger->error('social.admin_override_execution_failed', [
                'admin_id' => $adminId,
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

            $executorObj = $this->userService->findById($userId);
            if (!$executorObj) {
                return ['success' => false, 'message' => 'کاربر یافت نشد'];
            }

            $oldTrust = $this->trust->getTrustScore($executorObj, ModuleContext::SOCIAL_TASKS);

            $this->trust->evaluate($executorObj, ModuleContext::SOCIAL_TASKS, 'manual_adjustment', [
                'delta' => $delta,
                'reason' => $reason,
                'admin_id' => $adminId
            ]);

            $newTrust = $this->trust->getTrustScore($executorObj, ModuleContext::SOCIAL_TASKS);

            return ['success' => true, 'message' => 'امتیاز اعتماد با موفقیت تغییر کرد', 'old_trust' => $oldTrust, 'new_trust' => $newTrust];
        } catch (\Throwable $e) {
            $this->logger->error('social.admin_adjust_trust_failed', ['error' => $e->getMessage()]);
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\SocialTask\StartSocialTaskExecutionJob::class);
        return $job->handle($userId, $adId, $context);
    }


    /**
     * Section 8.2 — Idempotent shim. Repeated submits for the same
     * (userId, executionId) return the same cached result.
     */
    public function submitExecution(int $userId, int $executionId, array $payload = []): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\SocialTask\SubmitSocialTaskExecutionJob::class);
        return $job->handle($userId, $executionId, $payload);
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
        $proofUrl  = trim((string)($payload['proof_url']  ?? ''));
        $proofText = trim((string)($payload['proof_text'] ?? ''));

        // Fetch execution without lock first to retrieve ad_id for pipeline
        $execCheck = $this->model->getExecutionWithAd($executionId, $userId, false);
        if (!$execCheck) {
            return ['success' => false, 'message' => 'رکورد اجرا یافت نشد'];
        }
        $adId = (int)($execCheck->ad_id ?? 0);

        $pipeline = new \Core\Pipeline(\Core\Container::getInstance());
        $pipelinePayload = [
            'user_id' => $userId,
            'action' => 'task.social',
            'context' => [
                'task_id'          => $adId,
                'execution_id'     => $executionId,
                'video_hash'       => $payload['video_hash'] ?? null,
                'behavior_signals' => $payload['behavior_signals'] ?? []
            ]
        ];

        return $pipeline->send($pipelinePayload)->through([\App\Middleware\TaskFraudGuardMiddleware::class])->then(function() use ($executionId, $userId, $payload, $proofUrl, $proofText) {

        try {
                $saga = $this->context->getContainer()->make(\App\Services\SagaOrchestrator::class);
                $skip = false;
                $earlyResult = null;
                $exec = null;
                $score = null;
                $decision = null;
                $rewardPaid = 0;
                $rewardAmount = 0.0;
                $finalStatus = null;
                $currency = null;

                $saga->addStep(
                    'validate_and_score',
                    function () use ($executionId, $userId, $payload, $proofUrl, $proofText, &$exec, &$score, &$decision, &$skip, &$earlyResult, &$finalStatus, &$currency) {
                        $exec = $this->model->getExecutionWithAd($executionId, $userId, true);
            
                        if (!$exec) {
                            $skip = true;
                            $earlyResult = ['success' => false, 'message' => 'رکورد اجرا یافت نشد'];
                            return;
                        }
            
                        if ($exec->status !== 'pending') {
                            $skip = true;
                            $earlyResult = ['success' => false, 'message' => 'وضعیت اجرا برای ارسال معتبر نیست'];
                            return;
                        }
            
                        // Pipeline handles anti-fraud checks securely before the transaction
            
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
                                
                                $skip = true;
                                $earlyResult = [
                                    'success' => true,
                                    'status'  => 'pending_camera_verification',
                                    'message' => 'تسک شما مشکوک تشخیص داده شد. لطفاً با استفاده از دوربین هویت تصویری خود را تأیید کنید تا پاداش آزاد شود.',
                                    'score'   => $score['task_score'] ?? 0,
                                ];
                                return;
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
                        $currency = (string)($exec->currency ?? 'irt');
                    },
                    function (\Throwable $e) {}
                )->addStep(
                    'wallet_deposit',
                    function () use ($executionId, $userId, &$exec, &$score, &$decision, &$rewardPaid, &$rewardAmount, &$skip, &$currency) {
                        if ($skip) return;

                        if (!empty($decision['pay_reward'])) {
                            $rewardAmount = (float)$this->antiFraud->adjustedReward($userId, (float)$exec->price_per_task);
            
                            if ($rewardAmount > 0) {
                                $this->eventDispatcher->dispatch('social_task.reward_approved', [
                                    'user_id' => $userId,
                                    'execution_id' => $executionId,
                                    'ad_id' => (int)$exec->ad_id,
                                    'task_type' => $exec->task_type ?? null,
                                    'decision' => $decision['decision'] ?? null,
                                    'risk_score' => $score['score'] ?? null,
                                    'reward_amount' => $rewardAmount,
                                    'currency' => $currency
                                ]);
            
                                $rewardPaid = 1;
                                
                                $this->eventDispatcher->dispatchAsync('social_task.reward_paid', [
                                    'executor_id' => $userId,
                                    'execution_id' => $executionId,
                                    'reward_amount' => $rewardAmount,
                                    'currency' => $currency
                                ]);
                            }
                        }
                    },
                    function (\Throwable $e) use ($executionId) {
                        $this->logger->warning('saga.compensating.social_task_reward', ['execution_id' => $executionId]);
                    }
                )->addStep(
                    'update_status_and_outbox',
                    function () use ($executionId, $userId, $proofUrl, $proofText, &$exec, &$score, &$decision, &$rewardPaid, &$rewardAmount, &$skip, &$finalStatus, &$currency) {
                        if ($skip) return;

                        $this->model->updateExecutionStatus($executionId, $finalStatus, [
                            'proof_url' => $proofUrl !== '' ? $proofUrl : null,
                            'proof_text' => $proofText !== '' ? $proofText : null,
                            'anti_fraud_score' => (float)($score['score'] ?? 0),
                            'reward_paid' => $rewardPaid,
                            'reward_amount' => $rewardAmount
                        ]);
            
                        $this->eventDispatcher->dispatch('social_task.execution.completed', [
                            'execution_id' => $executionId,
                            'user_id' => $userId,
                            'status' => $finalStatus,
                            'reward_paid' => $rewardPaid,
                            'reward_amount' => $rewardAmount,
                            'currency' => $currency,
                            'score' => $score,
                            'decision' => $decision
                        ]);
                    },
                    function (\Throwable $e) use ($executionId) {
                        $this->logger->warning('saga.compensating.social_task_status', ['execution_id' => $executionId]);
                    }
                );

                $saga->execute();

                if ($skip) {
                    return $earlyResult;
                }
                
                return [
                    'success' => true, 
                    'message' => 'ارسال با موفقیت انجام شد', 
                    'status' => $finalStatus,
                    'score' => $score['score'] ?? 0
                ];
        } catch (\Throwable $e) {
            $this->logger->error('social.submit_execution_failed', [
                'user_id' => $userId,
                'execution_id' => $executionId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد'];
        }
        });
    }

    public function advertiserApprove(int $advertiserId, int $executionId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\SocialTask\ApproveSocialTaskExecutionJob::class);
        return $job->handle($advertiserId, $executionId);
    }


    public function advertiserReject(int $advertiserId, int $executionId, string $reason): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\SocialTask\RejectSocialTaskExecutionJob::class);
        return $job->handle($advertiserId, $executionId, $reason);
    }


        public function getAdById(int $adId): ?object
    {
        return $this->model->getAdById($adId);
    }

    public function getUserAccounts(int $userId): array
    {
        return [];
    }

    public function addAccount(int $userId, string $platform, string $username, string $accessToken = ''): array
    {
        return ['success' => false, 'message' => 'سرویس پروفایل موقتا در دسترس نیست'];
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
            return ['success' => false, 'message' => 'سرویس گزارش‌دهی موقتا در دسترس نیست'];
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
            return ['success' => false, 'message' => 'سرویس امتیازدهی موقتا در دسترس نیست'];
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
        $rating = $this->model->getAvgRating($userId, 'executor');

        return [
            'total_executions' => $stats->total ?? 0,
            'approved_count' => $stats->good_tasks ?? 0,
            'avg_rating' => round((float) ($rating->avg_stars ?? 0), 2),
            'rating_count' => (int) ($rating->total_ratings ?? 0),
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

