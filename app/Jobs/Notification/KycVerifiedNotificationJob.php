<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class KycVerifiedNotificationJob implements JobInterface
{
    public function handle(int $userId): ?int
    {

        return $this->sendFromTemplate($userId, 'kyc_approved', [],
            Notification::PRIORITY_HIGH, url('/dashboard'), 'ورود به داشبورد');
    
    }
}
