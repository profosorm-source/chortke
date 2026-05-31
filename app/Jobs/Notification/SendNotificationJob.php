<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class SendNotificationJob implements JobInterface
{
    public function handle(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal',
        ?string $expiresAt = null,
        ?string $imageUrl = null,
        ?string $groupKey = null,
        ?string $scheduledAt = null
    ): ?int
    {

        try {
            if (!$this->preferenceService->isNotificationEnabled($userId, $type)) {
                return null;
            }

            if (!$this->rateLimiter->attempt("notif_limit:{$userId}:{$type}", 5, 60)) {
                $this->logger->warning('notification.rate_limited', ['user_id' => $userId, 'type' => $type]);
                return null;
            }

            return $this->sendInternal(
                $userId,
                $type,
                $title,
                $message,
                $data,
                $actionUrl,
                $actionText,
                $priority,
                $expiresAt,
                $imageUrl,
                $groupKey,
                $scheduledAt
            );
        } catch (\Throwable $e) {
            $this->logger->error('notification.send_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage()
            ]);
            return null;
        }
    
    }
}
