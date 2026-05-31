<?php

declare(strict_types=1);

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelInterface;
use App\Adapters\Notification\FcmNotificationAdapter;

class FcmChannel implements NotificationChannelInterface
{
    public function __construct(private FcmNotificationAdapter $fcmAdapter) {}

    public function getName(): string
    {
        return 'fcm';
    }

    public function send(
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): bool {
        return $this->fcmAdapter->sendToUser($userId, $title, $message, $data ?? [], $imageUrl, $actionUrl);
    }
}
