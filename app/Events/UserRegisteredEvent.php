<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class UserRegisteredEvent extends Event
{
    public int $userId;
    public string $email;
    public string $ipAddress;
    public ?string $plainToken;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        string $email,
        string $ipAddress,
        ?string $plainToken = null,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->email = $email;
        $this->ipAddress = $ipAddress;
        $this->plainToken = $plainToken;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'email' => $email,
            'ip' => $ipAddress,
            'plain_token' => $plainToken,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
