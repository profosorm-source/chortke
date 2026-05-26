<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class EscrowReleasedEvent extends Event
{
    public function __construct(
        public readonly int $escrowId,
        public readonly int $userId,
        public readonly float $amount,
        public readonly string $currency = 'irt',
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'escrow_id' => $escrowId,
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
