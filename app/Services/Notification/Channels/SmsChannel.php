<?php

declare(strict_types=1);

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelInterface;
use App\Adapters\Notification\SmsNotificationAdapter;

class SmsChannel implements NotificationChannelInterface
{
    private SmsNotificationAdapter $smsAdapter;

    public function __construct(SmsNotificationAdapter $smsAdapter)
    {
        $this->smsAdapter = $smsAdapter;
    }

    public function getName(): string
    {
        return 'sms';
    }

    public function send(
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): bool {
        return $this->smsAdapter->sendToUser($userId, $message);
    }
}
