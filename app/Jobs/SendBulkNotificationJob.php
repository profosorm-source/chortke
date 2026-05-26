<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notification\NotificationDispatcher;
use Core\Cache;

/**
 * SendBulkNotificationJob - پردازش غیرهمزمان و پس‌زمینه دسته‌ای از نوتیفیکیشن‌ها
 */
class SendBulkNotificationJob
{
    private NotificationDispatcher $dispatcher;
    private Cache $cache;

    public function __construct(NotificationDispatcher $dispatcher, Cache $cache)
    {
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
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
        $messageId = $data['message_id'] ?? ($extraData['notif_id'] ?? null);

        if (empty($channel) || empty($userIds) || empty($title) || empty($message)) {
            return;
        }

        // 🚀 Bulk pre-fetch preferences to avoid N+1 database queries
        if (count($userIds) > 1) {
            try {
                $prefService = \Core\Container::getInstance()->make(
                    \App\Services\Notification\NotificationPreferenceService::class
                );
                $prefService->prefetchPreferences($userIds);
            } catch (\Throwable $e) {
            }
        }

        // پردازش تک‌تک کاربران در پس‌زمینه بدون مسدودسازی ریکوئست اصلی
        foreach ($userIds as $userId) {
            $dedupKey = null;
            if ($messageId) {
                $dedupKey = "notif_sent:{$channel}:{$messageId}:{$userId}";
                // Check cache to avoid duplicate dispatch within 24 hours
                if ($this->cache->get($dedupKey)) {
                    continue; // Skip already dispatched notification
                }
            }

            $success = $this->dispatcher->dispatch(
                $channel,
                (int)$userId,
                $title,
                $message,
                $extraData,
                $imageUrl,
                $actionUrl
            );

            if ($success && $dedupKey) {
                $this->cache->putSeconds($dedupKey, '1', 86400);
            }
        }
    }
}
