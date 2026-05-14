<?php

declare(strict_types=1);

namespace App\Adapters\Notification;

use Core\Logger;

class PushNotificationAdapter
{
    private FcmNotificationAdapter $fcmAdapter;
    private Logger $logger;

    public function __construct(FcmNotificationAdapter $fcmAdapter, Logger $logger)
    {
        $this->fcmAdapter = $fcmAdapter;
        $this->logger = $logger;
    }

    public function sendToUser(int $userId, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): bool
    {
        return $this->fcmAdapter->sendToUser($userId, $title, $body, $data, $imageUrl, $clickUrl);
    }
    
    public function sendToTokens(array $tokens, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): array
    {
        return $this->fcmAdapter->sendToTokens($tokens, $title, $body, $data, $imageUrl, $clickUrl);
    }
}


