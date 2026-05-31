<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WalletTransferInitiatingEvent extends Event
{
    public function __construct(
        public readonly int $fromUserId,
        public readonly int $toUserId,
        public readonly string $amount,
        public readonly string $currency,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
