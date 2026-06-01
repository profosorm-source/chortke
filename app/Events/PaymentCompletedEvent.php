<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class PaymentCompletedEvent extends Event
{
    public int $userId;
    public string $transactionId;
    public float $amount;
    public string $currency;
    public string $gateway;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        string $transactionId,
        float $amount,
        string $currency,
        string $gateway,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->transactionId = $transactionId;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->gateway = $gateway;
        $this->occurredAt = $occurredAt;

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
