<?php

declare(strict_types=1);

namespace App\Services\Notification;

use Core\RateLimiter;
use App\Services\SettingService;
use App\Contracts\LoggerInterface;
use App\Models\Notification;

/**
 * NotificationPolicyService
 * 
 * مسئولیت بررسی قوانین و محدودیت‌های ارسال نوتیفیکیشن
 * مانند Rate Limiting، وضعیت DND (Do Not Disturb) و ترجیحات کاربر.
 */
class NotificationPolicyService
{
    private const RATE_MAX_PER_USER_PER_HOUR = 20;
    private const RATE_WINDOW_MINUTES        = 60;

    public function __construct(
        private RateLimiter $rateLimiter,
        private SettingService $settingService,
        private NotificationPreferenceService $preferenceService,
        private LoggerInterface $logger
    ) {}

    /**
     * بررسی محدودیت نرخ ارسال برای کاربر
     */
    public function checkRateLimit(int $userId, string $type = ''): bool
    {
        $key = "notif_rl_user_{$userId}";
        $max = (int)$this->settingService->get('notif_rate_max_hour', self::RATE_MAX_PER_USER_PER_HOUR);
        $window = (int)$this->settingService->get('notif_rate_window_minutes', self::RATE_WINDOW_MINUTES);

        if (!$this->rateLimiter->attempt($key, $max, $window)) {
            $this->logger->info('notif.rate_limited', ['user_id' => $userId, 'type' => $type]);
            return false;
        }

        return true;
    }

    /**
     * حل زمان‌بندی ارسال با توجه به وضعیت DND
     */
    public function resolveScheduledTime(int $userId, string $priority, ?string $scheduledAt): ?string
    {
        if ($scheduledAt === null && $priority !== Notification::PRIORITY_URGENT) {
            if ($this->preferenceService->isInDndMode($userId)) {
                $deferredTime = $this->preferenceService->getNextDndEndTime($userId);
                $this->logger->info('notif.dnd_deferred', ['user_id' => $userId, 'scheduled_at' => $deferredTime]);
                return $deferredTime;
            }
        }
        return $scheduledAt;
    }

    /**
     * آیا دریافت پیام درون برنامه‌ای برای این نوع مجاز است؟
     */
    public function canSendInApp(int $userId, string $type): bool
    {
        return $this->preferenceService->isInAppEnabled($userId, $type);
    }

    /**
     * آیا دریافت Push برای این نوع مجاز است؟
     */
    public function canSendPush(int $userId, string $type): bool
    {
        return $this->preferenceService->isPushEnabled($userId, $type);
    }
    
    /**
     * بارگذاری اولیه ترجیحات به صورت Bulk (جلوگیری از N+1 Query)
     */
    public function prefetchPreferences(array $userIds): void
    {
        $this->preferenceService->prefetchPreferences($userIds);
    }
}
