<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class MarkAsReadNotificationJob implements JobInterface
{
    public function handle(int $notificationId, int $userId): bool
    {

        return $this->tracker->markAsRead($notificationId, $userId);
    
    }
}
