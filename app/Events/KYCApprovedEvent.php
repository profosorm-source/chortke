<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class KYCApprovedEvent extends Event
{
    public int $userId;
    public int $kycId;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        int $kycId,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->kycId = $kycId;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'kyc_id' => $kycId,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
