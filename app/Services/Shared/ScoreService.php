<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Models\Score;
use App\Models\User;
use App\Models\SocialTaskAnalyticsModel;
use App\Services\User\UserScoreService;
use App\Services\InfluencerReputationService;
use InvalidArgumentException;

use App\Contracts\LoggerInterface;
/**
 * ScoreService - سرویس اشتراکی مدیریت امتیازات (Scoring)
 * 
 * Consolidated from: TrustScoreService, UserScoreService, ScoreAdjustmentService, ScoreService
 * 
 * This service is a unified interface for all scoring systems:
 * - General Scoring
 * - User Scores
 * - Influencer Reputation
 * - Social Trust
 * - Admin adjustments
 */
class ScoreService extends \App\Services\BaseService
{
    private const TRUST_INITIAL = 50;
    private const TRUST_MIN = 0;
    private const TRUST_MAX = 100;

    // تغییرات مثبت trust score
    private const TRUST_INC_GOOD_TASK = 2;
    private const TRUST_INC_NATURAL_BEHAVIOR = 1;
    private const TRUST_INC_HEALTHY_WEEK = 2;

    // جریمه‌های trust score
    private const TRUST_DEC_REJECTED = -5;
    private const TRUST_DEC_SUSPICIOUS = -3;
    private const TRUST_DEC_SOFT_EXCESS = -2;
    private const TRUST_DEC_CONFIRMED_FRAUD = -10;

    public function __construct(
        private Database $db,
        protected LoggerInterface $logger,
        private Score $scoreModel,
        private User $userModel,
        private SocialTaskAnalyticsModel $socialTaskModel,
        private UserScoreService $userScoreService,
        private InfluencerReputationService $influencerReputationService
    ) {
        parent::__construct($logger);
    }

    /**
     * ثبت رویداد امتیازدهی unified
     */
    public function addEvent(
        int $entityId,
        string $entityType,
        string $domain,
        float $delta,
        string $source,
        array $meta = []
    ): bool {
        return $this->scoreModel->addEvent([
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'domain' => $domain,
            'delta' => $delta,
            'source' => $source,
            'meta' => $meta
        ]);
    }

    /**
     * ثبت رویداد امتیازدهی کاربر (legacy)
     */
    public function createEvent(int $userId, string $domain, string $source, float $delta, array $meta = []): bool
    {
        return $this->scoreModel->createEvent($userId, $domain, $source, $delta, $meta);
    }

    /**
     * دریافت امتیاز کل
     */
    public function getTotalScore(int $entityId, string $entityType, string $domain): float
    {
        return $this->scoreModel->getTotal($entityId, $entityType, $domain);
    }

    // ==========================================
    // Trust Score Management (from TrustScoreService)
    // ==========================================

    /**
     * Trust Score فعلی کاربر (0–100)
     */
    public function getTrustScore(int $userId): float
    {
        return $this->scoreModel->getTrustScore($userId);
    }

    /**
     * Trust Modifier برای محاسبه Task Score
     */
    public function getTrustModifier(int $userId): float
    {
        $trust = $this->getTrustScore($userId);

        if ($trust >= 80) return 10.0;
        if ($trust >= 60) return 5.0;
        if ($trust >= 40) return 0.0;
        if ($trust >= 20) return -5.0;
        return -10.0;
    }

    /**
     * پاداش تسک خوب
     */
    public function rewardGoodTask(int $userId, int $executionId): void
    {
        $this->applyTrustDelta($userId, self::TRUST_INC_GOOD_TASK, 'good_task', ['execution_id' => $executionId]);
    }

    /**
     * جریمه رد شدن
     */
    public function penalizeRejection(int $userId, int $executionId): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_REJECTED, 'rejection', ['execution_id' => $executionId]);
    }

    /**
     * جریمه رفتار مشکوک
     */
    public function penalizeSuspicious(int $userId, string $reason): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_SUSPICIOUS, 'suspicious_behavior', ['reason' => $reason]);
    }

    /**
     * جریمه تایید نرم افزاری بیش از حد
     */
    public function penalizeSoftExcess(int $userId): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_SOFT_EXCESS, 'soft_approved_excess', []);
    }

    /**
     * جریمه تقلب تایید شده
     */
    public function penalizeConfirmedFraud(int $userId, string $reason): void
    {
        $this->applyTrustDelta($userId, self::TRUST_DEC_CONFIRMED_FRAUD, 'confirmed_fraud', ['reason' => $reason]);
    }

    /**
     * Cron — بهبود هفتگی
     */
    public function processWeeklyRecovery(): array
    {
        $updated = 0;
        $checked = 0;

        $users = $this->socialTaskModel->getRecentActiveExecutors(7);

        foreach ($users as $row) {
            $userId = (int)($row->user_id ?? $row['user_id']);
            $checked++;

            $stats = $this->getWeeklyStats($userId);

            if ($stats['rejected'] === 0 && $stats['good_tasks'] >= 5) {
                $this->applyTrustDelta($userId, self::TRUST_INC_HEALTHY_WEEK, 'weekly_recovery', $stats);
                $updated++;
            }

            if ($stats['soft_approved'] >= 3) {
                $this->penalizeSoftExcess($userId);
            }

            $this->saveTrustSnapshot($userId);
        }

        return ['checked' => $checked, 'updated' => $updated];
    }

    /**
     * دریافت آمار هفتگی اجرا
     */
    public function getWeeklyStats(int $userId): array
    {
        $row = $this->scoreModel->getWeeklyExecutionStats($userId);

        if (!$row) {
            return ['total' => 0, 'good_tasks' => 0, 'rejected' => 0, 'soft_approved' => 0, 'avg_score' => 0];
        }

        return [
            'total' => (int)($row->total ?? 0),
            'good_tasks' => (int)($row->good_tasks ?? 0),
            'rejected' => (int)($row->rejected ?? 0),
            'soft_approved' => (int)($row->soft_approved ?? 0),
            'avg_score' => round((float)($row->avg_score ?? 0), 1),
        ];
    }

    /**
     * اعمال تغییر trust score
     */
    private function applyTrustDelta(int $userId, float $delta, string $source, array $meta): void
    {
        $current = $this->getTrustScore($userId);
        $newVal = $this->clampTrustScore($current + $delta);

        $this->scoreModel->updateTrustScore($userId, $newVal);

        $this->createEvent(
            $userId,
            'social_trust',
            $source,
            $delta,
            array_merge($meta, [
                'old_trust' => $current,
                'new_trust' => $newVal,
            ])
        );
    }

    /**
     * ذخیره snapshot trust score
     */
    private function saveTrustSnapshot(int $userId): void
    {
        $trust = $this->getTrustScore($userId);
        $stats = $this->getWeeklyStats($userId);

        $this->scoreModel->saveTrustSnapshot([
            'user_id' => $userId,
            'trust_score' => $trust,
            'week_good_tasks' => $stats['good_tasks'],
            'week_rejected' => $stats['rejected'],
            'week_soft' => $stats['soft_approved']
        ]);
    }

    /**
     * محدود کردن trust score بین 0-100
     */
    private function clampTrustScore(float $val): float
    {
        return max(self::TRUST_MIN, min(self::TRUST_MAX, $val));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // User Score Delegation Methods
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ثبت event امتیاز و اعمال delta
     */
    public function applyEventDelta(
        int $userId,
        string $domain,
        float $delta,
        string $source,
        array $meta = []
    ): bool {
        return $this->userScoreService->applyEventDelta($userId, $domain, $delta, $source, $meta);
    }

    /**
     * دریافت fraud score کاربر
     */
    public function getFraudScore(int $userId): float
    {
        return $this->userScoreService->getFraudScore($userId);
    }

    /**
     * دریافت task score کاربر
     */
    public function getTaskScore(int $userId): float
    {
        return $this->userScoreService->getTaskScore($userId);
    }

    /**
     * دریافت effective score (پس از تعدیل‌ها)
     */
    public function getEffectiveScore(int $userId, string $domain, float $rawScore): float
    {
        return $this->userScoreService->getEffectiveScore($userId, $domain, $rawScore);
    }

    /**
     * دریافت تعدیل‌های فعال
     */
    public function getActiveAdjustments(int $userId, string $domain): array
    {
        return $this->scoreModel->getActiveAdjustments($userId, $domain);
    }

    /**
     * ایجاد تعدیل امتیاز (legacy method)
     */
    public function createAdjustment(
        int $userId,
        string $domain,
        float $adjustment,
        string $reason,
        ?string $expiry = null,
        ?int $createdBy = null
    ): array {
        return $this->userScoreService->createAdjustment($userId, $domain, $adjustment, $reason, $expiry, $createdBy);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Influencer Reputation Delegation Methods
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * دریافت reputation stats اینفلوئنسر
     */
    public function getInfluencerStats(int $profileId): array
    {
        return $this->influencerReputationService->getPublicStats($profileId);
    }

    /**
     * امتیازدهی پس از رفع اختلاف
     */
    public function scoreAfterDisputeResolution(object $dispute, string $verdict, string $resolvedBy): void
    {
        $this->influencerReputationService->scoreAfterDisputeResolution($dispute, $verdict, $resolvedBy);
    }

    // ==========================================
    // Score Adjustment Management (from ScoreAdjustmentService)
    // ==========================================

    /**
     * اعمال تنظیم امتیاز توسط ادمین
     */
    public function adjust(
        int $adminId,
        int $userId,
        string $domain,
        string $operation,
        float $value,
        string $reason,
        ?string $expiresAt = null
    ): bool {
        $this->validateAdjustment($domain, $operation, $value, $reason);

        $ok = $this->scoreModel->createAdjustment([
            'user_id' => $userId,
            'domain' => $domain,
            'operation' => $operation,
            'value' => $value,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_by' => $adminId,
        ]);

        if ($ok) {
            $this->createEvent($userId, $domain, 'admin_adjustment', 0, [
                'operation' => $operation,
                'value' => $value,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'admin_id' => $adminId,
            ]);
        }

        return $ok;
    }

    /**
     * لغو تنظیم امتیاز توسط ادمین
     */
    public function revokeAdjustment(int $adminId, int $adjustmentId, string $reason): bool
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Revoke reason is required.');
        }

        return $this->scoreModel->revokeAdjustment($adjustmentId, $adminId, $reason);
    }

    /**
     * اعتبار سنجی تنظیم امتیاز
     */
    private function validateAdjustment(string $domain, string $operation, float $value, string $reason): void
    {
        if (!in_array($domain, ['fraud', 'task', 'social_trust'], true)) {
            throw new InvalidArgumentException('Invalid score domain.');
        }

        if (!in_array($operation, ['set', 'add', 'subtract'], true)) {
            throw new InvalidArgumentException('Invalid score operation.');
        }

        if ($value < 0) {
            throw new InvalidArgumentException('Adjustment value cannot be negative.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Adjustment reason is required.');
        }
    }

    // ==========================================
    // Utility Methods
    // ==========================================

    /**
     * دریافت اطلاعات کاربر برای مدیریت امتیازات
     */
    public function getUserForScoreManagement(int $userId): ?object
    {
        return $this->userModel->findById($userId);
    }

    /**
     * دریافت ریسک خام تسک برای کاربر
     */
    public function getTaskRawRisk(int $userId): float
    {
        return $this->scoreModel->getTaskRawRisk($userId);
    }

    /**
     * دریافت رویدادهای اخیر امتیازدهی کاربر
     */
    public function getRecentScoreEvents(int $userId, int $limit = 50): array
    {
        return $this->scoreModel->getRecentEvents($userId, $limit);
    }

    /**
     * دریافت رویدادهای امتیازدهی کاربر (legacy)
     */
    public function getEventsByUser(int $userId, ?string $domain = null, int $limit = 200): array
    {
        return $this->scoreModel->getEventsByUser($userId, $domain, $limit);
    }

    /**
     * افزایش fraud raw score کاربر
     */
    public function incrementFraudRawScore(int $userId, float $delta, string $source, array $meta = []): bool
    {
        return $this->userScoreService->incrementFraudRawScore($userId, $delta, $source, $meta);
    }
}

