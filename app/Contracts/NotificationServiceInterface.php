<?php

declare(strict_types=1);

namespace App\Contracts;

interface NotificationServiceInterface
{
    public function send(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data        = null,
        ?string $actionUrl   = null,
        ?string $actionText  = null,
        string  $priority    = 'normal',
        ?string $expiresAt   = null,
        ?string $imageUrl    = null,
        ?string $groupKey    = null,
        ?string $scheduledAt = null
    ): ?int;

    public function sendToAdmins(
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        string $priority = 'normal'
    ): int;

    public function dispatch(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ): bool;

    public function depositSuccess(int $userId, float $amount, string $currency): ?int;
}
