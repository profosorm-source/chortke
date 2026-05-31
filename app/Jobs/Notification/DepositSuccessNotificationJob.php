<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class DepositSuccessNotificationJob implements JobInterface
{
    public function handle(int $userId, float $amount, string $currency): ?int
    {

        return $this->sendFromTemplate($userId, 'deposit', [
            'amount'   => \Core\ValueObjects\Money::fromString((string)($amount))->format(),
            'currency' => strtoupper($currency),
        ], Notification::PRIORITY_HIGH, url('/wallet'), 'مشاهده کیف پول');
    
    }
}
