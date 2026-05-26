<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class LevelUpgradedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly string $oldLevel, // Changed to string (slug) to match service layer
        public readonly string $newLevel, // Changed to string (slug)
        public readonly string $reason = 'automatic',
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'old_level' => $oldLevel,
            'new_level' => $newLevel,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
