<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class SecurityAlertNotificationJob implements JobInterface
{
    public function handle(int $userId, string $message, string $ip): ?int
    {

        $id = $this->sendFromTemplate($userId, 'security', [
            'message' => $message,
            'ip'      => $ip,
        ], Notification::PRIORITY_URGENT, url('/profile/security'), 'بررسی حساب');

        return $id;
    
    }
}
