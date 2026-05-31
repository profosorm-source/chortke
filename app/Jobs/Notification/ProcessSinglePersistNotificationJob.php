<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

class ProcessSinglePersistNotificationJob
{
    public function __construct(
        
    ) {}

    public function handle(
        int $uid, string $type, string $title, string $message,
        ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt
    ): bool {
        // ۱. ارزیابی محدودیت نرخ ارسال (Rate Limit)
        if (!$this->checkRateLimit($uid)) {
            return false;
        }

        // ۲. ارزیابی و حل زمان ارسال زمان‌بندی شده
        $resTime = $this->resolveScheduledTime($uid, $priority, $scheduledAt);

        // ۳. ثبت فیزیکی در دیتابیس
        return (bool)$this->persistInAppNotification(
            $uid, $type, $title, $message, $data,
            $actionUrl, $actionText, $priority, null, null, null, $resTime
        );
    }
}
