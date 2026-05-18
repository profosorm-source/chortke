<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notification\NotificationService;

/**
 * PersistBulkInAppNotificationJob - پردازش و درج غیرهمزمان نوتیفیکیشن‌های درون‌برنامه‌ای در دیتابیس
 */
class PersistBulkInAppNotificationJob
{
    private NotificationService $service;

    public function __construct(NotificationService $service)
    {
        $this->service = $service;
    }

    /**
     * اجرای تسک ثبت نوتیفیکیشن در دیتابیس برای دسته‌ای از کاربران
     */
    public function handle(array $data): void
    {
        $userIds = $data['user_ids'] ?? [];
        $type = $data['type'] ?? '';
        $title = $data['title'] ?? '';
        $message = $data['message'] ?? '';
        $extraData = $data['data'] ?? null;
        $actionUrl = $data['action_url'] ?? null;
        $actionText = $data['action_text'] ?? null;
        $priority = $data['priority'] ?? 'normal';
        $scheduledAt = $data['scheduled_at'] ?? null;

        if (empty($userIds) || empty($title) || empty($message)) {
            return;
        }

        // 🚀 Bulk pre-fetch preferences to avoid N+1 database queries
        $this->service->prefetchPreferences($userIds);

        foreach ($userIds as $uid) {
            $this->service->processSinglePersist(
                (int)$uid,
                $type,
                $title,
                $message,
                $extraData,
                $actionUrl,
                $actionText,
                $priority,
                $scheduledAt
            );
        }
    }
}
