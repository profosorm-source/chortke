<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class AccountDeletedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly string $reason,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'email' => $email,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
