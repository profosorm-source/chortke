<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class UserLoggedInEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
