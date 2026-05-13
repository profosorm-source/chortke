<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\Shared\ScoreService;
use App\Contracts\LoggerInterface;

/**
 * TrustScoreService — کلاس سازگاری برای بازگرداندن متد گت از ScoreService ادغام‌شده
 */
class TrustScoreService extends \App\Services\BaseService
{
    public function __construct(
        private ScoreService $scoreService,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * دریافت امتیاز اعتماد کاربر
     */
    public function get(int $userId): float
    {
        return $this->scoreService->getTrustScore($userId);
    }

    /**
     * دریافت تعدیل‌کننده امتیاز اعتماد
     */
    public function getModifier(int $userId): float
    {
        return $this->scoreService->getTrustModifier($userId);
    }

    /**
     * پاداش تسک خوب
     */
    public function rewardGoodTask(int $userId, int $executionId): void
    {
        $this->scoreService->rewardGoodTask($userId, $executionId);
    }

    /**
     * جریمه رد شدن تسک
     */
    public function penalizeRejection(int $userId, int $executionId): void
    {
        $this->scoreService->penalizeRejection($userId, $executionId);
    }

    /**
     * جریمه رفتار مشکوک
     */
    public function penalizeSuspicious(int $userId, string $reason): void
    {
        $this->scoreService->penalizeSuspicious($userId, $reason);
    }
}
