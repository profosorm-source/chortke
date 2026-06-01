<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WalletTransferInitiatingEvent extends Event
{
    public int $fromUserId;
    public int $toUserId;
    public string $amount;
    public string $currency;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $fromUserId,
        int $toUserId,
        string $amount,
        string $currency,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->fromUserId = $fromUserId;
        $this->toUserId = $toUserId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'from_user_id' => $fromUserId,
            'to_user_id' => $toUserId,
            'amount' => $amount,
            'currency' => $currency,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
