<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class PaymentCompletedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $transactionId,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $gateway,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'currency' => $currency,
            'gateway' => $gateway,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
