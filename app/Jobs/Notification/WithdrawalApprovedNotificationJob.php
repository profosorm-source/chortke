<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class WithdrawalApprovedNotificationJob implements JobInterface
{
    public function handle(int $userId, float $amount, string $currency): ?int
    {

        $id = $this->sendFromTemplate($userId, 'withdrawal', [
            'amount'   => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
            'currency' => strtoupper($currency),
        ], Notification::PRIORITY_HIGH, url('/wallet/history'), 'مشاهده تاریخچه');

        return $id;
    
    }
}
