<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class EscrowReleasedEvent extends Event
{
    public int $escrowId;
    public int $userId;
    public float $amount;
    public string $currency;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $escrowId,
        int $userId,
        float $amount,
        string $currency = 'irt',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->escrowId = $escrowId;
        $this->userId = $userId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'escrow_id' => $escrowId,
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
