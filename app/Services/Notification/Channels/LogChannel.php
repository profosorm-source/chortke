<?php

declare(strict_types=1);

namespace App\Services\Notification\Channels;

use App\Contracts\NotificationChannelInterface;
use App\Adapters\Notification\LogNotificationAdapter;

class LogChannel implements NotificationChannelInterface
{
    private LogNotificationAdapter $logAdapter;

    public function __construct(LogNotificationAdapter $logAdapter)
    {
        $this->logAdapter = $logAdapter;
    }

    public function getName(): string
    {
        return 'log';
    }

    public function send(
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): bool {
        return $this->logAdapter->sendAlert($title, $message);
    }
}
