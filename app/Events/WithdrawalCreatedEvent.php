<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WithdrawalCreatedEvent extends Event
{
    public int $userId;
    public int $withdrawalId;
    public float $amount;
    public string $currency;
    public string $status;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        int $withdrawalId,
        float $amount,
        string $currency = 'irt',
        string $status = 'pending',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->withdrawalId = $withdrawalId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->status = $status;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'withdrawal_id' => $withdrawalId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
