<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class ScoreUpdatedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly float $oldScore,
        public readonly float $newScore,
        public readonly string $reason = '',
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'old_score' => $oldScore,
            'new_score' => $newScore,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
