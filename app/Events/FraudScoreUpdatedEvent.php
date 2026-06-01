<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

/**
 * 🚀 UPG-05: FraudScoreUpdatedEvent - رویداد تغییر و ثبت امتیاز فراد نهایی کاربر
 */
class FraudScoreUpdatedEvent extends Event
{
    public int $userId;
    public int $score;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        int $score,
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->score = $score;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id'     => $userId,
            'score'       => $score,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
