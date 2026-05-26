<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ScoreService;
use Core\Cache;

/**
 * UpdateFraudScoreJob — ثبت ناهمگام تغییرات امتیاز فراد در دیتابیس برای مقابله با DoS
 */
class UpdateFraudScoreJob
{
    private ScoreService $scoreService;
    private Cache $cache;

    public function __construct(ScoreService $scoreService, Cache $cache)
    {
        $this->scoreService = $scoreService;
        $this->cache = $cache;
    }

    public function handle(array $data): void
    {
        $userId = (int)($data['user_id'] ?? 0);
        $delta  = (float)($data['delta'] ?? 0);
        $domain = $data['domain'] ?? 'fraud';
        $source = $data['source'] ?? 'unknown';
        $meta   = $data['meta'] ?? [];

        if ($userId <= 0 || $delta == 0.0) {
            return;
        }

        // Physical writes executed inside background context using Unified ScoreService
        $this->scoreService->applyDelta('user', $userId, $domain, $delta, $source, $meta);
    }
}
