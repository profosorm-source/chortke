<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WithdrawalApprovedEvent extends Event
{
    public int $userId;
    public int $withdrawalId;
    public float $amount;
    public string $currency;
    public int $approvedBy;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        int $withdrawalId,
        float $amount,
        string $currency = 'irt',
        int $approvedBy = 0,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->withdrawalId = $withdrawalId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->approvedBy = $approvedBy;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'withdrawal_id' => $withdrawalId,
            'amount' => $amount,
            'currency' => $currency,
            'approved_by' => $approvedBy,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
