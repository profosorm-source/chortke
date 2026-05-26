<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class DisputeOpenedEvent extends Event
{
    public function __construct(
        public readonly int $disputeId,
        public readonly int $userId,
        public readonly ?int $orderId = null,
        public readonly string $reason = '',
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'dispute_id' => $disputeId,
            'user_id' => $userId,
            'order_id' => $orderId,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
