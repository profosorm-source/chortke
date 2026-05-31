<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class InvalidateUnreadCacheNotificationJob implements JobInterface
{
    public function handle(int $userId): void
    {

        $this->tracker->invalidateUnreadCache($userId);
    
    }
}
