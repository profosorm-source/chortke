<?php

declare(strict_types=1);

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelInterface;
use App\Adapters\Notification\PushNotificationAdapter;

class PushChannel implements NotificationChannelInterface
{
    public function __construct(private PushNotificationAdapter $pushAdapter) {}

    public function getName(): string
    {
        return 'push';
    }

    public function send(
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): bool {
        return $this->pushAdapter->sendToUser($userId, $title, $message, $data ?? [], $imageUrl, $actionUrl);
    }
}
