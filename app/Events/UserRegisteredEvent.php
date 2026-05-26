<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class UserRegisteredEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly string $ipAddress,
        public readonly ?string $plainToken = null,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'email' => $email,
            'ip' => $ipAddress,
            'plain_token' => $plainToken,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
