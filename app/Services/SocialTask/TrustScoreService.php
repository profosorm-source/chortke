<?php

declare(strict_types=1);

namespace App\Services\SocialTask;

use App\Services\Shared\ScoreService;

/**
 * TrustScoreService — کلاس سازگاری برای بازگرداندن متد گت از ScoreService ادغام‌شده
 */
class TrustScoreService
{
    private ScoreService $scoreService;

    public function __construct(ScoreService $scoreService)
    {
        $this->scoreService = $scoreService;
    }

    /**
     * دریافت امتیاز اعتماد کاربر
     */
    public function get(int $userId): float
    {
        return $this->scoreService->getTrustScore($userId);
    }
}
