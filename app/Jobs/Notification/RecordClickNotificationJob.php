<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class RecordClickNotificationJob implements JobInterface
{
    public function handle(int $notificationId, int $userId): bool
    {

        return $this->model->recordClick($notificationId, $userId);
    
    }
}
