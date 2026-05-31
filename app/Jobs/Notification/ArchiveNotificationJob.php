<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class ArchiveNotificationJob implements JobInterface
{
    public function handle(int $notificationId, int $userId): bool
    {

        return $this->tracker->archive($notificationId, $userId);
    
    }
}
