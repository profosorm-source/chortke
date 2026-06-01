<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class InvestmentCreatedEvent extends Event
{
    public int $userId;
    public int $investmentId;
    public float $amount;
    public string $currency;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        int $investmentId,
        float $amount,
        string $currency = 'usdt',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->investmentId = $investmentId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'investment_id' => $investmentId,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
