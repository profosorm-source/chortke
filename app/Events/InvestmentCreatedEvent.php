<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class InvestmentCreatedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly int $investmentId,
        public readonly float $amount,
        public readonly string $currency = 'usdt',
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'investment_id' => $investmentId,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
