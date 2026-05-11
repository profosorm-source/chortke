<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\User\UserScoreService;
use Core\Cache;

/**
 * UpdateFraudScoreJob — ثبت ناهمگام تغییرات امتیاز فراد در دیتابیس برای مقابله با DoS
 */
class UpdateFraudScoreJob
{
    private UserScoreService $scoreService;
    private Cache $cache;

    public function __construct(UserScoreService $scoreService, Cache $cache)
    {
        $this->scoreService = $scoreService;
        $this->cache = $cache;
    }

    public function handle(array $data): void
    {
        $userId = (int)($data['user_id'] ?? 0);
        $delta  = (float)($data['delta'] ?? 0);
        $source = $data['source'] ?? 'unknown';
        $meta   = $data['meta'] ?? [];

        if ($userId <= 0 || $delta == 0) {
            return;
        }

        // اعمال واقعی و نهایی تغییرات روی دیتابیس
        $this->scoreService->commitDeltaToDatabase($userId, 'fraud', $delta, $source, $meta);

        // کسر کردن این مقدار ثبت شده از بافر موقت کش (در صورت استفاده از معماری بافرینگ پیچیده)
        // در روش Queue ساده ما، فقط اعمال می‌کنیم.
    }
}
