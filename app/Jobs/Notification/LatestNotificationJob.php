<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class LatestNotificationJob implements JobInterface
{
    public function handle(int $userId, int $limit = 10): array
    {

        return $this->tracker->getLatestForUser($userId, $limit);
    
    }
}
