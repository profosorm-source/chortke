<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class SoftDeleteNotificationJob implements JobInterface
{
    public function handle(int $notificationId, int $userId): bool
    {

        return $this->tracker->softDelete($notificationId, $userId);
    
    }
}
