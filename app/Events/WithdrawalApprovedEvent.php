<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class WithdrawalApprovedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly int $withdrawalId,
        public readonly float $amount,
        public readonly string $currency = 'irt',
        public readonly int $approvedBy = 0,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
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
