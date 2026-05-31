<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class FindForUserNotificationJob implements JobInterface
{
    public function handle(int $notificationId, int $userId): ?object
    {

        $notification = $this->model->find($notificationId);
        if (!$notification || (int)$notification->user_id !== $userId) {
            return null;
        }
        return $notification;
    
    }
}
