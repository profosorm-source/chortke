<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WalletDebitedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reason,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
