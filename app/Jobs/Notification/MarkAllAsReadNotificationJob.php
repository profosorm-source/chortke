<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class MarkAllAsReadNotificationJob implements JobInterface
{
    public function handle(int $userId): bool
    {

        return $this->tracker->markAllAsRead($userId);
    
    }
}
