<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class UserLoggedInEvent extends Event
{
    public int $userId;
    public string $ipAddress;
    public string $userAgent;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        string $ipAddress,
        string $userAgent,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
