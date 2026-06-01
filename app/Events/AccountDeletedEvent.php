<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class AccountDeletedEvent extends Event
{
    public int $userId;
    public string $email;
    public string $reason;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        string $email,
        string $reason,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->email = $email;
        $this->reason = $reason;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'email' => $email,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
