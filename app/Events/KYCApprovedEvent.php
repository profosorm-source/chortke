<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class KYCApprovedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly int $kycId,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'kyc_id' => $kycId,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
