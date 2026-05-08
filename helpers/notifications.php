<?php

function notify_admins(string $type, string $title, string $message, ?string $url = null, ?array $data = null): int
{
    $type = trim($type);
    $type = preg_replace('/[^A-Za-z0-9_]/', '', $type);
    if ($type === '') {
        $type = 'system';
    }

    $title = mb_substr(trim(strip_tags($title)), 0, 255);
    $message = mb_substr(trim(strip_tags($message)), 0, 1200);
    $url = $url !== null ? filter_var(trim($url), FILTER_VALIDATE_URL) : null;

    $limiter = app(\Core\RateLimiter::class);
    if (!$limiter->attempt('notify_admins:' . $type, 5, 1)) {
        return 0;
    }

    $service = app(\App\Services\NotificationService::class);
    $result = $service->sendToAll($title, $message, $type, $url, null, 'normal', $data);
    return $result['sent'] ?? 0;
}

function unread_notifications_count(?int $userId = null): int
{
    $service = app(\App\Services\NotificationService::class);
    return $service->getUnreadCount($userId);
}