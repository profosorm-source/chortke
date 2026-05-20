<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\Shared\TrustScoreService as SharedTrustScoreService;
use App\Contracts\LoggerInterface;

/**
 * SocialTask trust facade.
 *
 * This compatibility facade now talks directly to the dedicated Shared\TrustScoreService
 * instead of going through the broad ScoreService orchestrator. This removes the
 * logical SocialTask -> ScoreService -> TrustScoreService coupling loop.
 */
class TrustScoreService extends \App\Services\BaseService
{
    public function __construct(
        private SharedTrustScoreService $trustScoreService,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * دریافت امتیاز اعتماد کاربر
     */
    public function get(int $userId): float
    {
        return $this->trustScoreService->getTrustScore($userId);
    }

    /**
     * دریافت تعدیل‌کننده امتیاز اعتماد
     */
    public function getModifier(int $userId): float
    {
        return $this->trustScoreService->getTrustModifier($userId);
    }

    /**
     * پاداش تسک خوب
     */
    public function rewardGoodTask(int $userId, int $executionId): void
    {
        $this->trustScoreService->rewardGoodTask($userId, $executionId);
    }

    /**
     * جریمه رد شدن تسک
     */
    public function penalizeRejection(int $userId, int $executionId): void
    {
        $this->trustScoreService->penalizeRejection($userId, $executionId);
    }

    /**
     * جریمه رفتار مشکوک
     */
    public function penalizeSuspicious(int $userId, string $reason): void
    {
        $this->trustScoreService->penalizeSuspicious($userId, $reason);
    }


    /**
     * جریمه soft-approved زیاد
     */
    public function penalizeSoftExcess(int $userId): void
    {
        $this->trustScoreService->penalizeSoftExcess($userId);
    }

    /**
     * جریمه تقلب قطعی
     */
    public function penalizeConfirmedFraud(int $userId, string $reason): void
    {
        $this->trustScoreService->penalizeConfirmedFraud($userId, $reason);
    }

    /**
     * بازیابی هفتگی Trust Score
     */
    public function processWeeklyRecovery(int $chunkSize = 100): array
    {
        return $this->trustScoreService->processWeeklyRecovery($chunkSize);
    }

    /**
     * آمار هفتگی کاربر برای داشبوردها
     */
    public function getWeeklyStats(int $userId): array
    {
        return $this->trustScoreService->getWeeklyStats($userId);
    }

}
