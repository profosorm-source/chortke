<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notification\NotificationDispatcher;

/**
 * SendBulkNotificationJob - پردازش غیرهمزمان و پس‌زمینه دسته‌ای از نوتیفیکیشن‌ها
 */
class SendBulkNotificationJob
{
    private NotificationDispatcher $dispatcher;

    public function __construct(NotificationDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * اجرای تسک پس‌زمینه
     */
    public function handle(array $data): void
    {
        $channel = $data['channel'] ?? '';
        $userIds = $data['user_ids'] ?? [];
        $title = $data['title'] ?? '';
        $message = $data['message'] ?? '';
        $extraData = $data['data'] ?? null;
        $imageUrl = $data['image_url'] ?? null;
        $actionUrl = $data['action_url'] ?? null;

        if (empty($channel) || empty($userIds) || empty($title) || empty($message)) {
            return;
        }

        // پردازش تک‌تک کاربران در پس‌زمینه بدون مسدودسازی ریکوئست اصلی
        foreach ($userIds as $userId) {
            $this->dispatcher->dispatch(
                $channel,
                (int)$userId,
                $title,
                $message,
                $extraData,
                $imageUrl,
                $actionUrl
            );
        }
    }
}
