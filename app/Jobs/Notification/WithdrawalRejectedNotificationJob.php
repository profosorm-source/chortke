<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class WithdrawalRejectedNotificationJob implements JobInterface
{
    public function handle(int $userId, float $amount, string $reason): ?int
    {

        return $this->sendFromTemplate($userId, 'withdrawal_rejected', [
            'amount' => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
            'reason' => $reason,
        ], Notification::PRIORITY_HIGH, url('/wallet/history'), 'مشاهده جزئیات');
    
    }
}
