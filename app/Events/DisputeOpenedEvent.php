<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class DisputeOpenedEvent extends Event
{
    public int $disputeId;
    public int $userId;
    public ?int $orderId;
    public string $reason;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $disputeId,
        int $userId,
        ?int $orderId = null,
        string $reason = '',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->disputeId = $disputeId;
        $this->userId = $userId;
        $this->orderId = $orderId;
        $this->reason = $reason;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'dispute_id' => $disputeId,
            'user_id' => $userId,
            'order_id' => $orderId,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
