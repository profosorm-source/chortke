<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ads;
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
    private Ads $adModel;
    private SeoExecution $executionModel;
    private UserScoreService $scoreService;
    private SeoPayoutService $payoutService;
    private SeoFraudDetector $fraudDetector;
    private WalletService $walletService;
    private ReferralService $referralService;
    private Database $db;
    private \App\Services\Shared\RatingService $ratingService;

    public function __construct(
        Ads $adModel,
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
        $this->db->beginTransaction();
        // قفل کردن آگهی برای جلوگیری از Race Condition
        $ad = $this->db->query("SELECT * FROM ads WHERE id = ? FOR UPDATE", [$adId])->fetch(\PDO::FETCH_OBJ);
        
        if (!$ad) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        if ($ad->status !== 'active') {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'آگهی فعال نیست'];
        }

        if ($ad->remaining_budget < $ad->min_payout) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'بودجه آگهی تمام شده است'];
        }

        // بررسی تکراری
        if ($this->executionModel->existsByAdAndUserToday($adId, $userId)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'شما امروز این تسک را قبلاً انجام داده‌اید'];
        }

        // بررسی محدودیت روزانه کاربر
        $todayCount = $this->executionModel->countByUserToday($userId);
        if ($todayCount >= $ad->max_per_day) {
            $this->db->rollBack();
            return ['success' => false, 'message' => "حداکثر {$ad->max_per_day} تسک در روز مجاز است"];
        }

        // بررسی محدودیت ساعتی
        $hourlyCount = $this->executionModel->countByUserLastHour($userId);
        if ($hourlyCount >= self::MAX_TASKS_PER_HOUR) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'حداکثر ' . self::MAX_TASKS_PER_HOUR . ' تسک در ساعت مجاز است. لطفاً کمی صبر کنید'];
        }

        // بررسی IP
        $ip = get_client_ip();
        $ipHourly = $this->executionModel->countByIPLastHour($ip);
        if ($ipHourly >= self::MAX_IP_TASKS_PER_HOUR) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'محدودیت IP. لطفاً بعداً تلاش کنید'];
        }

        try {
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

            $this->db->commit();
            return ['success' => true, 'session_id' => $sessionId];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('seo_task.start_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }

    /**
     * تکمیل تسک و محاسبه پاداش
     */
     public function completeTask(int $executionId, int $userId, array $engagementData): array
    {
        $this->db->beginTransaction();
        // قفل گذاری روی سطر اجرا و آگهی برای جلوگیری از Double Payout
        $execution = $this->db->query("SELECT * FROM seo_executions WHERE id = ? AND user_id = ? FOR UPDATE", [$executionId, $userId])->fetch(\PDO::FETCH_OBJ);
        
        if (!$execution) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'تسک یافت نشد'];
        }

        if ($execution->status !== 'started') {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'این تسک قبلاً پردازش شده است'];
        }

        $ad = $this->db->query("SELECT * FROM ads WHERE id = ? FOR UPDATE", [$execution->ad_id])->fetch(\PDO::FETCH_OBJ);
        
        if (!$ad) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        try {
            // 1. اعتبارسنجی داده‌ها
            if (!isset($engagementData['duration'], $engagementData['scroll_depth'], $engagementData['interactions'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'داده‌های تعامل ناقص است'];
            }

            // 2. محاسبه امتیاز
            $scores = $this->calculateEngagementScore($engagementData);

            // 3. تشخیص تقلب
            $fraudCheck = $this->fraudDetector->detect($userId, $ad->id, $engagementData);
            
            if ($fraudCheck['is_fraud']) {
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
            $walletResult = $this->walletService->deposit(
                $userId,
                $payout,
                'irt',
                [
                    'source' => 'seo_task_reward',
                    'description' => "تسک SEO - {$ad->title}",
                    'execution_id' => $executionId,
                    'ad_id' => $ad->id
                ]
            );

            if (empty($walletResult['success'])) {
                $this->db->rollBack();
                $this->logger->error('seo_task.wallet_credit_failed', ['user_id' => $userId]);
                return ['success' => false, 'message' => 'خطا در واریز پاداش'];
            }

            // 9. پورسانت ریفرال
            $userRecord = \App\Core\Container::getInstance()->get(\App\Models\User::class)->findById($userId);
            if ($userRecord && !empty($userRecord->referred_by)) {
                $referralService = \App\Core\Container::getInstance()->get(\App\Services\Shared\ReferralService::class);
                if ($referralService) {
                    $referralService->processCommission((int)$userRecord->referred_by, $payout, 'irt', [
                        'action' => 'seo_task_reward',
                        'executor_id' => $userId,
                        'execution_id' => $executionId
                    ]);
                }
            }

            $this->db->commit();
            return [
                'success' => true,
                'message' => 'تسک با موفقیت تایید شد و پاداش واریز گردید',
                'payout' => $payout,
                'score' => $scores['final_score'],
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

