<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\FcmNotificationAdapter;
use App\Contracts\LoggerInterface;

class FcmService
{
    private FcmNotificationAdapter $adapter;
    public function __construct(
        FcmNotificationAdapter $adapter
    ) {        $this->adapter = $adapter;

            }

    public function sendToUser(int $userId, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): bool
    {
        return $this->adapter->sendToUser($userId, $title, $body, $data, $imageUrl, $clickUrl);
    }

    public function sendToTokens(array $tokens, string $title, string $body, array $data = [], ?string $imageUrl = null, ?string $clickUrl = null): array
    {
        return $this->adapter->sendToTokens($tokens, $title, $body, $data, $imageUrl, $clickUrl);
    }

    public function saveUserToken(int $userId, string $token, string $platform = 'web'): bool
    {
        return $this->adapter->saveUserToken($userId, $token, $platform);
    }

    public function getUserToken(int $userId, string $platform = 'web'): ?string
    {
        return $this->adapter->getUserToken($userId, $platform);
    }

    public function removeUserToken(int $userId, string $platform = 'web'): void
    {
        $this->adapter->removeUserToken($userId, $platform);
    }

    public function isConfigured(): bool
    {
        return $this->adapter->isConfigured();
    }
}


