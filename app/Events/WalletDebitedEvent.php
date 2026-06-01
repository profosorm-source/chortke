<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WalletDebitedEvent extends Event
{
    public int $userId;
    public string $amount;
    public string $currency;
    public string $reason;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        string $amount,
        string $currency,
        string $reason,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->reason = $reason;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
