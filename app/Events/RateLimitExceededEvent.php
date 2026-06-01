<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class RateLimitExceededEvent extends Event
{
    public string $key;
    public string $strategy;
    public string $ipAddress;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        string $key,
        string $strategy,
        string $ipAddress,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->key = $key;
        $this->strategy = $strategy;
        $this->ipAddress = $ipAddress;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'key' => $key,
            'strategy' => $strategy,
            'ip' => $ipAddress,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
