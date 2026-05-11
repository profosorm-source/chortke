<?php

use App\Services\Notification\NotificationService;

function notify_admins(string $type, string $title, string $message, ?string $url = null, ?array $data = null): int
{
    $limiter = app(\Core\RateLimiter::class);
    // Throttle to prevent notification flooding
    if (!$limiter->attempt('notify_admins_helper:' . $type, 10, 1)) {
        return 0;
    }

    // Merging URL into data to support the unified sendToAdmins interface
    $data = is_array($data) ? $data : [];
    if ($url !== null) {
        $data['action_url'] = $url;
    }

    $service = app(NotificationService::class);
    return $service->sendToAdmins($type, $title, $message, $data);
}

function unread_notifications_count(?int $userId = null): int
{
    $userId = $userId ?? user_id();
    if (!$userId) return 0;
    
    return app(NotificationService::class)->getUnreadCount($userId);
}