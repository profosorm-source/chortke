<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ads;
use App\Models\SeoExecution;
use App\Services\SeoPayoutService;
use App\Services\AntiFraud\SeoFraudDetector;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\ReferralService;
use Core\Database;
use App\Services\SettingService;
use App\Contracts\LoggerInterface;
use App\Models\User;

class SeoService extends \App\Services\BaseService
{
    private SettingService $settingService;
    private Ads $adModel;
    private SeoExecution $executionModel;
    private SeoPayoutService $payoutService;
    private SeoFraudDetector $fraudDetector;
    private WalletServiceInterface $walletService;
    private ReferralService $referralService;
    private \App\Services\Interaction\RatingService $ratingService;
    private \App\Services\Interaction\ReportService $reportService;
    private User $userModel;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;

    public function __construct(
        Ads $adModel,
        SeoExecution $executionModel,
        SeoPayoutService $payoutService,
        SeoFraudDetector $fraudDetector,
        WalletServiceInterface $walletService,
        ReferralService $referralService,
        Database $db,
        \App\Services\Interaction\RatingService $ratingService,
        \App\Services\Interaction\ReportService $reportService,
        LoggerInterface $logger,
        User $userModel,
        SettingService $settingService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        \Core\EventDispatcher $eventDispatcher
    ) {
        parent::__construct($logger, null, null, null, null, null, null, $eventDispatcher);
        $this->settingService = $settingService;
        $this->adModel = $adModel;
        $this->executionModel = $executionModel;
        $this->payoutService = $payoutService;
        $this->fraudDetector = $fraudDetector;
        $this->walletService = $walletService;
        $this->referralService = $referralService;
        $this->db = $db;
        $this->ratingService = $ratingService;
        $this->reportService = $reportService;
        $this->userModel = $userModel;
        $this->fraudGuard = $fraudGuard;
    }

    /**
     * ایجاد آگهی جدید به همراه کسر بودجه از کیف پول (به صورت تراکنشی و ایمن)
     */
    public function createAd(int $userId, array $data, float $budget, float $minPayout, float $maxPayout): array
    {
        try {
            return $this->transaction(function() use ($userId, $data, $budget, $minPayout, $maxPayout) {
                // کسر از کیف پول
                $debit = $this->walletService->pay(
                    $userId,
                    (string)$budget,
                    'irt',
                    [
                        'type' => 'seo_ad',
                        'description' => 'SEO Ad: ' . $data['keyword'],
                        'ref_type' => 'seo_ad',
                    ]
                );
                
                if (empty($debit['success'])) {
                    throw new \RuntimeException($debit['message'] ?? 'موجودی کافی نیست.');
                }
        
                // ایجاد آگهی
                $adId = $this->adModel->create([
                    'user_id' => $userId,
                    'type' => 'seo',
                    'site_url' => $data['site_url'],
                    'title' => $data['title'] ?? $data['keyword'],
                    'keyword' => $data['keyword'],
                    'description' => $data['description'] ?? null,
                    'budget' => $budget,
                    'remaining_budget' => $budget,
                    'min_payout' => $minPayout,
                    'max_payout' => $maxPayout,
                    'target_duration' => (int)($data['target_duration'] ?? 60),
                    'min_score' => (int)($data['min_score'] ?? 40),
                    'max_per_day' => (int)($data['max_per_day'] ?? 10),
                    'deadline' => !empty($data['deadline']) ? $data['deadline'] : null,
                    'status' => 'pending',
                ]);
        
                if (!$adId) {
                    throw new \RuntimeException('خطا در ثبت آگهی در دیتابیس.');
                }
                
                return ['success' => true, 'ad_id' => $adId];
            });
        } catch (\Exception $e) {
            $this->logger->error('seo_ad.create_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    /**
     * شروع تسک توسط کاربر
     */
    public function startTask(int $adId, int $userId): array
    {
        try {
            return $this->transaction(function() use ($adId, $userId) {
                // قفل کردن آگهی برای جلوگیری از Race Condition
                $ad = $this->db->query("SELECT * FROM ads WHERE id = ? FOR UPDATE", [$adId])->fetch(\PDO::FETCH_OBJ);
                
                if (!$ad) {
                    return ['success' => false, 'message' => 'آگهی یافت نشد'];
                }
        
                if ($ad->status !== 'active') {
                    return ['success' => false, 'message' => 'آگهی فعال نیست'];
                }
        
                if ($ad->remaining_budget < $ad->min_payout) {
                    return ['success' => false, 'message' => 'بودجه آگهی تمام شده است'];
                }
        
                // بررسی تکراری
                if ($this->executionModel->existsByAdAndUserToday($adId, $userId)) {
                    return ['success' => false, 'message' => 'شما امروز این تسک را قبلاً انجام داده‌اید'];
                }
        
                // بررسی محدودیت روزانه کاربر
                $todayCount = $this->executionModel->countByUserToday($userId);
                if ($todayCount >= $ad->max_per_day) {
                    return ['success' => false, 'message' => "حداکثر {$ad->max_per_day} تسک در روز مجاز است"];
                }
        
                // بررسی محدودیت ساعتی
                $hourlyLimit = (int)$this->settingService->get('seo_max_tasks_per_hour', 5);
                $hourlyCount = $this->executionModel->countByUserLastHour($userId);
                if ($hourlyCount >= $hourlyLimit) {
                    return ['success' => false, 'message' => "حداکثر {$hourlyLimit} تسک در ساعت مجاز است. لطفاً کمی صبر کنید"];
                }
        
                // بررسی IP
                $ip = get_client_ip();
                $ipLimit = (int)$this->settingService->get('seo_max_ip_tasks_per_hour', 10);
                $ipHourly = $this->executionModel->countByIPLastHour($ip);
                if ($ipHourly >= $ipLimit) {
                    return ['success' => false, 'message' => 'محدودیت IP. لطفاً بعداً تلاش کنید'];
                }
        
                $fingerprint = function_exists('generate_device_fingerprint') 
                    ? generate_device_fingerprint() 
                    : md5($_SERVER['HTTP_USER_AGENT'] ?? '' . $ip);
    
                $sessionId = bin2hex(random_bytes(16));
    
                $this->executionModel->create([
                    'ad_id' => $adId,
                    'user_id' => $userId,
                    'session_id' => $sessionId,
                    'status' => 'started',
                    'ip_address' => $ip,
                    'device_fingerprint' => $fingerprint,
                    'started_at' => date('Y-m-d H:i:s'),
                    'target_keyword' => $ad->keyword,
                ]);
    
                return ['success' => true, 'session_id' => $sessionId];
            });
        } catch (\Exception $e) {
            $this->logger->error('seo_task.start_failed', ['error' => $e->getMessage()]);
            if (str_contains($e->getMessage(), '1062') || str_contains($e->getMessage(), 'Duplicate entry')) {
                return ['success' => false, 'message' => 'شما امروز این تسک را قبلاً انجام داده‌اید'];
            }
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }

    /**
     * تکمیل تسک و محاسبه پاداش
     */
     public function completeTask(int $executionId, int $userId, array $engagementData): array
    {
        try {
            return $this->transaction(function() use ($executionId, $userId, $engagementData) {
                // قفل گذاری روی سطر اجرا برای جلوگیری از Double Payout
                $execution = $this->executionModel->findByIdForUpdate($executionId);
                
                if (!$execution || $execution->user_id !== $userId) {
                    return ['success' => false, 'message' => 'تسک یافت نشد'];
                }
        
                if ($execution->status !== 'started') {
                    return ['success' => false, 'message' => 'این تسک قبلاً پردازش شده است'];
                }
        
                // قفل گذاری روی آگهی برای بررسی و کسر بودجه
                $ad = $this->adModel->findByIdForUpdate($execution->ad_id);
                
                if (!$ad) {
                    return ['success' => false, 'message' => 'آگهی یافت نشد'];
                }
    
                // 1. اعتبارسنجی داده‌ها
                if (!isset($engagementData['duration'], $engagementData['scroll_depth'], $engagementData['interactions'])) {
                    return ['success' => false, 'message' => 'داده‌های تعامل ناقص است'];
                }
    
                // 2. محاسبه امتیاز
                $scores = $this->calculateEngagementScore($engagementData);
    
                // 🛡️ گیت ضدتقلب مرکزی سئو (شامل Session tracking و Risk Decision و تشخیص هوشمند تقلب)
                $risk = $this->fraudGuard->checkAction($userId, 'task.seo', [
                    'ad_id'           => $ad->id,
                    'execution_id'    => $executionId,
                    'session_id'      => $execution->session_id ?? '',
                    'engagement_data' => $engagementData
                ]);
    
                $seoFraud = $risk['details']['seo_fraud'] ?? null;
                $isFraud = $seoFraud && !empty($seoFraud['is_fraud']);
    
                if (empty($risk['allowed']) || $isFraud) {
                    $flags = $seoFraud['flags'] ?? ['blocked_by_security_policy'];
                    $this->executionModel->markAsFraud($executionId, $flags);
                    $this->fraudDetector->addToBlacklist($userId, implode(', ', $flags));
    
                    $this->logger->warning('seo_task.blocked_by_fraud_guard', [
                        'user_id'      => $userId,
                        'execution_id' => $executionId,
                        'reason'       => $risk['reason'] ?? 'fraud_detected'
                    ]);
    
                    return [
                        'success'        => false,
                        'message'        => $isFraud ? 'تعامل شما معتبر تشخیص داده نشد' : 'تکمیل تسک به دلایل نظارتی مسدود شد.',
                        'fraud_detected' => true,
                    ];
                }
    
                // 4. بررسی حداقل امتیاز
                if ($scores['final_score'] < $ad->min_score) {
                    $this->executionModel->reject($executionId, "امتیاز کمتر از حد مجاز ({$ad->min_score})");
                    return [
                        'success' => false,
                        'message' => "امتیاز شما ({$scores['final_score']}) کمتر از حداقل مجاز ({$ad->min_score}) است",
                        'score' => $scores['final_score'],
                    ];
                }
    
                // 5. محاسبه پاداش
                $payoutResult = $this->payoutService->calculatePayout($ad->id, $scores['final_score']);
                if (!$payoutResult['can_pay']) {
                    $this->executionModel->reject($executionId, $payoutResult['message']);
                    return [
                        'success' => false,
                        'message' => $payoutResult['message'],
                    ];
                }
    
                $payout = $payoutResult['payout'];
    
                // 6. تکمیل Execution (با چک کردن تغییر وضعیت اتمیک)
                if (!$this->executionModel->complete($executionId, $scores, $payout)) {
                    throw new \Exception('این تسک قبلاً تکمیل یا لغو شده است');
                }
    
                // 7. کسر از بودجه آگهی (با تایید موفقیت تراکنش انتقال بودجه امانی)
                if (!$this->payoutService->deductFromBudget($ad->id, $payout)) {
                    throw new \Exception('موجودی امانی آگهی کافی نیست');
                }
    
                $adCurrency = strtolower((string)($ad->currency ?? $this->settingService->get('currency_mode', 'irt')));
                if (!in_array($adCurrency, ['irt', 'usdt'])) {
                    $adCurrency = 'irt';
                }
    
                // 8. واریز به کیف پول کاربر
                $walletResult = $this->walletService->depositInTransaction(
                    $userId,
                    $payout,
                    $adCurrency,
                    [
                        'source' => 'seo_task_reward',
                        'description' => "تسک SEO - {$ad->title}",
                        'execution_id' => $executionId,
                        'ad_id' => $ad->id
                    ]
                );
    
                if (empty($walletResult['success'])) {
                    throw new \Exception('خطا در واریز پاداش');
                }
    
                // 9. پورسانت ریفرال (event-driven)
                $userRecord = $this->userModel->findById($userId);
                if ($userRecord && !empty($userRecord->referred_by)) {
                    // Migrated to event-driven referral commission
                    $this->eventDispatcher?->dispatch('referral.commission.process', [
                        'referrer_id' => (int)$userRecord->referred_by,
                        'amount' => $payout,
                        'currency' => $adCurrency,
                        'source_user_id' => $userId,
                        'context' => [
                            'action' => 'seo_task_reward',
                            'executor_id' => $userId,
                            'execution_id' => $executionId
                        ]
                    ]);
                }
    
                return [
                    'success' => true,
                    'message' => 'تسک با موفقیت تایید شد و پاداش واریز گردید',
                    'payout' => $payout,
                    'score' => $scores['final_score'],
                ];
            });
        } catch (\Exception $e) {
            $this->logger->error('seo_task.complete_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage() !== 'خطای سیستمی' ? $e->getMessage() : 'خطای سیستمی'];
        }
    }


    /**
     * لغو تسک (قبل از تکمیل)
     */
    public function cancelTask(int $executionId, int $userId): array
    {
        $execution = $this->executionModel->findByUser($executionId, $userId);
        
        if (!$execution || $execution->status !== 'started') {
            return ['success' => false, 'message' => 'تسک قابل لغو نیست'];
        }

        $this->executionModel->reject($executionId, 'لغو شده توسط کاربر');

        return ['success' => true, 'message' => 'تسک لغو شد'];
    }

    /**
     * محاسبه امتیاز تعامل (0-100)
     */
    private function calculateEngagementScore(array $data): array
    {
        $duration = (int)($data['duration'] ?? 0);
        $scrollDepth = (float)($data['scroll_depth'] ?? 0);
        $interactions = (int)($data['interactions'] ?? 0);
        $behavior = $data['behavior'] ?? [];

        // Time Score (0-30)
        $timeScore = 0;
        if ($duration >= 300) $timeScore = 30;
        elseif ($duration >= 120) $timeScore = 20;
        elseif ($duration >= 60) $timeScore = 10;

        // Scroll Score (0-25)
        $scrollScore = 0;
        if ($scrollDepth >= 80) $scrollScore = 25;
        elseif ($scrollDepth >= 50) $scrollScore = 18;
        elseif ($scrollDepth >= 20) $scrollScore = 10;

        // Interaction Score (0-25)
        $interactionScore = 0;
        if ($interactions >= 7) $interactionScore = 25;
        elseif ($interactions >= 4) $interactionScore = 18;
        elseif ($interactions >= 1) $interactionScore = 10;

        // Quality Score (0-20)
        $qualityScore = 20;
        $scrollSpeed = $behavior['scroll_speed'] ?? 0;
        $mousePattern = $behavior['mouse_pattern'] ?? 'normal';
        $pauseCount = $behavior['pause_count'] ?? 0;
        $interactionTypes = $behavior['interaction_types'] ?? [];

        if ($scrollSpeed > 5000) $qualityScore -= 7;
        elseif ($scrollSpeed > 3000) $qualityScore -= 3;
        if ($mousePattern === 'linear' || $mousePattern === 'none') $qualityScore -= 5;
        if ($pauseCount < 2) $qualityScore -= 4;
        if (count($interactionTypes) < 2) $qualityScore -= 4;
        $qualityScore = max(0, $qualityScore);

        $finalScore = $timeScore + $scrollScore + $interactionScore + $qualityScore;

        return [
            'time_score' => round($timeScore, 2),
            'scroll_score' => round($scrollScore, 2),
            'interaction_score' => round($interactionScore, 2),
            'quality_score' => round($qualityScore, 2),
            'final_score' => round($finalScore, 2),
            'engagement_data' => $data,
        ];
    }

    /**
     * گزارش تخلف تسک سئو
     */
    public function reportTask(int $reporterId, int $adId, string $reason, string $description = ''): array
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        try {
            $ok = $this->reportService->submit(
                $reporterId,
                'seo_task',
                $adId,
                \App\Enums\ModuleContext::GLOBAL,
                $reason,
                $description
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت گزارش'];
            }

            return ['success' => true, 'message' => 'گزارش با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }

    /**
     * امتیازدهی به تسک سئو
     */
    public function rateTask(int $raterId, int $adId, int $stars, string $comment = ''): array
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        $stars = max(1, min(5, $stars));

        try {
            $ok = $this->ratingService->rate(
                $raterId,
                'seo_task',
                $adId,
                \App\Enums\ModuleContext::GLOBAL,
                $stars
            );

            if (!$ok) {
                return ['success' => false, 'message' => 'خطا در ثبت امتیاز'];
            }

            return ['success' => true, 'message' => 'امتیاز با موفقیت ثبت شد'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'خطای سیستمی: ' . $e->getMessage()];
        }
    }
    /**
     * تایید آگهی توسط ادمین
     */
    public function approveAd(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) return false;

        $ok = $this->adModel->update($adId, [
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.approved', "آگهی SEO #{$adId} تایید شد", user_id(), ['ad_id' => $adId]);
            try {
                \Core\EventDispatcher::getInstance()->dispatch('seo_ad.approved', [
                    'ad_id' => $adId,
                    'module' => 'seo_ad',
                    'type' => 'seo_ad'
                ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.approved.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }

    /**
     * رد آگهی توسط ادمین
     */
    public function rejectAd(int $adId, string $reason): bool
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) return false;

        $ok = $this->adModel->update($adId, [
            'status' => 'rejected',
            'rejection_reason' => $reason ?: 'مدیر رد کرد',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.rejected', "آگهی SEO #{$adId} رد شد", user_id(), ['ad_id' => $adId, 'reason' => $reason]);
            try {
                \Core\EventDispatcher::getInstance()->dispatch('seo_ad.rejected', [
                    'ad_id' => $adId,
                    'module' => 'seo_ad',
                    'type' => 'seo_ad'
                ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.rejected.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }

    /**
     * متوقف کردن آگهی توسط ادمین
     */
    public function pauseAd(int $adId): bool
    {
        $ad = $this->adModel->find($adId);
        if (!$ad) return false;

        $ok = $this->adModel->update($adId, [
            'status' => 'paused',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($ok) {
            $this->logger->activity('seo_ad.paused', "آگهی SEO #{$adId} متوقف شد", user_id(), ['ad_id' => $adId]);
            try {
                \Core\EventDispatcher::getInstance()->dispatch('seo_ad.paused', [
                    'ad_id' => $adId,
                    'module' => 'seo_ad',
                    'type' => 'seo_ad'
                ]);
            } catch (\Throwable $evtErr) {
                $this->logger->warning('seo_ad.paused.event_failed', [
                    'ad_id' => $adId,
                    'error' => $evtErr->getMessage()
                ]);
            }
        }
        return $ok;
    }
}

