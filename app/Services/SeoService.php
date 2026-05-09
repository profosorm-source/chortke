<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SeoAd;
use App\Models\SeoExecution;
use App\Services\User\UserScoreService;
use App\Services\SeoPayoutService;
use App\Services\AntiFraud\SeoFraudDetector;
use App\Services\WalletService;
use App\Services\Shared\ReferralService;
use Core\Database;

use App\Contracts\LoggerInterface;
/**
 * SeoService — سرویس اصلی مدیریت تسک‌های SEO
 */
class SeoService extends \App\Services\BaseService
{
    public const MAX_TASKS_PER_HOUR = 5;
    public const MAX_IP_TASKS_PER_HOUR = 10;
    private SeoAd $adModel;
    private SeoExecution $executionModel;
    private UserScoreService $scoreService;
    private SeoPayoutService $payoutService;
    private SeoFraudDetector $fraudDetector;
    private WalletService $walletService;
    private ReferralService $referralService;
    private Database $db;
    private \App\Services\Shared\RatingService $ratingService;

    public function __construct(
        SeoAd $adModel,
        SeoExecution $executionModel,
        UserScoreService $scoreService,
        SeoPayoutService $payoutService,
        SeoFraudDetector $fraudDetector,
        WalletService $walletService,
        ReferralService $referralService,
        Database $db,
        \App\Services\Shared\RatingService $ratingService,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->adModel = $adModel;
        $this->executionModel = $executionModel;
        $this->scoreService = $scoreService;
        $this->payoutService = $payoutService;
        $this->fraudDetector = $fraudDetector;
        $this->walletService = $walletService;
        $this->referralService = $referralService;
        $this->db = $db;
        $this->ratingService = $ratingService;
    }

    /**
     * شروع تسک توسط کاربر
     */
    public function startTask(int $adId, int $userId): array
    {
        $ad = $this->adModel->find($adId);
        
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
        $hourlyCount = $this->executionModel->countByUserLastHour($userId);
        if ($hourlyCount >= self::MAX_TASKS_PER_HOUR) {
            return ['success' => false, 'message' => 'حداکثر ' . self::MAX_TASKS_PER_HOUR . ' تسک در ساعت مجاز است. لطفاً کمی صبر کنید'];
        }

        // بررسی IP
        $ip = get_client_ip();
        $ipHourly = $this->executionModel->countByIPLastHour($ip);
        if ($ipHourly >= self::MAX_IP_TASKS_PER_HOUR) {
            return ['success' => false, 'message' => 'محدودیت IP. لطفاً بعداً تلاش کنید'];
        }

        // بررسی Blacklist
        if ($this->fraudDetector->isBlacklisted($userId)) {
            return ['success' => false, 'message' => 'در حال حاضر امکان انجام تسک وجود ندارد'];
        }

        // ایجاد Execution
        try {
            $fingerprint = function_exists('generate_device_fingerprint') 
                ? generate_device_fingerprint() 
                : null;

            $execution = $this->executionModel->create([
                'ad_id' => $adId,
                'user_id' => $userId,
                'ip_address' => $ip,
                'device_fingerprint' => $fingerprint,
            ]);

            if (!$execution) {
                return ['success' => false, 'message' => 'خطا در شروع تسک'];
            }

            $this->logger->info('seo_task.started', ['user_id' => $userId, 'ad_id' => $adId]);

            return [
                'success' => true,
                'message' => 'تسک شروع شد',
                'execution' => $execution,
                'ad' => $ad,
                'config' => [
                    'target_duration' => $ad->target_duration,
                    'min_score' => $ad->min_score,
                    'min_payout' => $ad->min_payout,
                    'max_payout' => $ad->max_payout,
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error('seo_task.start_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }

    /**
     * تکمیل تسک و محاسبه پاداش
     */
    public function completeTask(int $executionId, int $userId, array $engagementData): array
    {
        $execution = $this->executionModel->findByUser($executionId, $userId);
        
        if (!$execution) {
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        if ($execution->status !== 'started') {
            return ['success' => false, 'message' => 'این تسک قبلاً تکمیل شده است'];
        }

        $ad = $this->adModel->find($execution->ad_id);
        
        if (!$ad) {
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        try {
            $this->db->beginTransaction();

            // 1. اعتبارسنجی داده‌ها
            if (!isset($engagementData['duration'], $engagementData['scroll_depth'], $engagementData['interactions'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'داده‌های تعامل ناقص است'];
            }

            // 2. محاسبه امتیاز (استفاده از منطق داخلی)
            $scores = $this->calculateEngagementScore($engagementData);

            // 3. تشخیص تقلب
            $fraudCheck = $this->fraudDetector->detect($userId, $ad->id, $engagementData);
            
            if ($fraudCheck['is_fraud']) {
                // علامت‌گذاری به عنوان تقلب
                $this->executionModel->markAsFraud($executionId, $fraudCheck['flags']);
                $this->fraudDetector->addToBlacklist($userId, implode(', ', $fraudCheck['flags']));
                
                $this->db->commit();
                
                $this->logger->warning('seo_task.fraud_detected', ['user_id' => $userId, 'execution_id' => $executionId, 'flags' => $fraudCheck['flags']]);
                
                return [
                    'success' => false,
                    'message' => 'تعامل شما معتبر تشخیص داده نشد',
                    'fraud_detected' => true,
                ];
            }

            // 4. بررسی حداقل امتیاز
            if ($scores['final_score'] < $ad->min_score) {
                $this->executionModel->reject($executionId, "امتیاز کمتر از حد مجاز ({$ad->min_score})");
                $this->db->commit();
                
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
                $this->db->commit();
                
                return [
                    'success' => false,
                    'message' => $payoutResult['message'],
                ];
            }

            $payout = $payoutResult['payout'];

            // 6. تکمیل Execution
            $this->executionModel->complete($executionId, $scores, $payout);

            // 7. کسر از بودجه آگهی
            $this->payoutService->deductFromBudget($ad->id, $payout);

            // 8. واریز به کیف پول کاربر
            $walletResult = $this->walletService->credit(
                $userId,
                $payout,
                'irt',
                'seo_task',
                "تسک SEO - {$ad->title}",
                ['ad_id' => $ad->id, 'execution_id' => $executionId]
            );

            if (!$walletResult['success']) {
                $this->db->rollBack();
                $this->logger->error('seo_task.wallet_credit_failed', ['user_id' => $userId]);
                return ['success' => false, 'message' => 'خطا در واریز پاداش'];
            }

            // 9. پورسانت ریفرال داینامیک و ماژولار چورتکه
            try {
                $this->referralService->processModularCommission(
                    $userId,
                    'google_search',
                    $payout,
                    'irt',
                    ['type' => 'seo_task', 'execution_id' => $executionId]
                );
            } catch (\Exception $e) {
                $this->logger->error('seo_task.referral_commission_failed', ['error' => $e->getMessage()]);
            }

            // 10. تخصیص امتیاز تجربه (XP) گیمیفای شده
            try {
                $xpEngine = \Core\Container::getInstance()->make(\App\Services\XPEngine::class);
                $xpEngine->awardXP($userId, 'google_search', 'seo_task_completed');
            } catch (\Throwable $t) {
                $this->logger->error('seo_task.xp_award_failed', ['error' => $t->getMessage()]);
            }

            $this->db->commit();

            $this->logger->info('seo_task.completed', ['user_id' => $userId, 'execution_id' => $executionId, 'payout' => $payout]);

            return [
                'success' => true,
                'message' => 'تسک با موفقیت تکمیل شد',
                'payout' => $payout,
                'score' => $scores['final_score'],
                'scores' => $scores,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('seo_task.complete_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی'];
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
            $ok = $this->ratingService->report([
                'reporter_id' => $reporterId,
                'ref_type' => 'seo_task',
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
                (int)$ad->user_id,
                'seo_task',
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
}

