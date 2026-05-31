<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class KycRejectedNotificationJob implements JobInterface
{
    public function handle(int $userId, string $reason): ?int
    {

        return $this->sendFromTemplate($userId, 'kyc_rejected', [
            'reason' => $reason,
        ], Notification::PRIORITY_URGENT, url('/kyc/upload'), 'ارسال مجدد مدارک');
    
    }
}
