<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class LotteryWinnerNotificationJob implements JobInterface
{
    public function handle(int $userId, float $amount): ?int
    {

        return $this->send(
            $userId,
            Notification::TYPE_LOTTERY,
            '🎉 تبریک! برنده شدید!',
            'شما برنده قرعه‌کشی شدید! مبلغ ' . \Core\ValueObjects\Money::fromString((string)($amount))->format() . ' به کیف پول شما واریز شد.',
            ['amount' => $amount],
            url('/wallet'),
            'مشاهده کیف پول',
            Notification::PRIORITY_URGENT,
            date('Y-m-d H:i:s', strtotime('+7 days'))
        );
    
    }
}
