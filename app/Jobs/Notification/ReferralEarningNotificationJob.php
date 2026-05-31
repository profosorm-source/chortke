<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class ReferralEarningNotificationJob implements JobInterface
{
    public function handle(int $userId, float $amount, string $referredUserName): ?int
    {

        return $this->sendFromTemplate($userId, 'referral', [
            'referred_user' => $referredUserName,
            'amount'        => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
        ], Notification::PRIORITY_NORMAL, url('/referral'), 'مشاهده زیرمجموعه‌ها', 'referral_earning');
    
    }
}
