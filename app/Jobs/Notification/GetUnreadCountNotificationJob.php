<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class GetUnreadCountNotificationJob implements JobInterface
{
    public function handle(int $userId): int
    {

        return $this->tracker->getUnreadCount($userId);
    
    }
}
