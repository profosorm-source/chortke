<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class DispatchNotificationJob implements JobInterface
{
    public function handle(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ): bool
    {

        return $this->dispatcher->dispatch($channel, $userId, $title, $message, $data, $imageUrl, $actionUrl, $actionText, $priority);
    
    }
}
