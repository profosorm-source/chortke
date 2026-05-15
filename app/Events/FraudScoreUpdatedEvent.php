<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

/**
 * 🚀 UPG-05: FraudScoreUpdatedEvent - رویداد تغییر و ثبت امتیاز فراد نهایی کاربر
 */
class FraudScoreUpdatedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly int $score,
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id'     => $userId,
            'score'       => $score,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
