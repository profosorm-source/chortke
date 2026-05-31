<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class CountUserNotificationsNotificationJob implements JobInterface
{
    public function handle(int $userId, bool $onlyUnread = false): int
    {

        return $this->tracker->countUserNotifications($userId, $onlyUnread);
    
    }
}
