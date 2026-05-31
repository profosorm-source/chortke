<?php

declare(strict_types=1);

namespace App\Jobs\SocialTask;

class CalculateSilentRiskScoreJob
{
    public function __construct(
        
    ) {}

    public function handle(int $userId, array $context = []): array
    {
        $cacheKey = "risk_score:{$userId}:" . md5(json_encode($context));
        
        $cached = cache()->get($cacheKey);
        if ($cached !== null) {
            return (array)$cached;
        }

        $result = $this->_calculateRiskScore($userId, $context);
        
        // Cache risk score calculation for 5 minutes to prevent IP check timing attacks (HIGH-NEW-03)
        cache()->put($cacheKey, $result, 5); // 5 minutes

        return $result;
    }
}
