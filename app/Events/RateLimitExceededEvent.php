<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class RateLimitExceededEvent extends Event
{
    public function __construct(
        public readonly string $key,
        public readonly string $strategy,
        public readonly string $ipAddress,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'key' => $key,
            'strategy' => $strategy,
            'ip' => $ipAddress,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
