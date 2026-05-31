<?php

declare(strict_types=1);

namespace App\Contracts;

interface NotificationChannelInterface
{
    /**
     * Get the unique string identifier for this channel (e.g., 'sms', 'email', 'fcm').
     */
    public function getName(): string;

    /**
     * Send the notification through the channel.
     *
     * @param int $userId The recipient's user ID.
     * @param string $title The notification title.
     * @param string $message The notification message body.
     * @param array|null $data Additional contextual data.
     * @param string|null $imageUrl URL of an image attachment.
     * @param string|null $actionUrl Call-to-action URL.
     * @return bool True if successfully dispatched/sent, false otherwise.
     */
    public function send(
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): bool;
}
