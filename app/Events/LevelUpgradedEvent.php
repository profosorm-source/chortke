<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class LevelUpgradedEvent extends Event
{
    public int $userId;
    public string $oldLevel;
    public string $newLevel;
    public string $reason;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        string $oldLevel, // Changed to string (slug) to match service layer
        string $newLevel, // Changed to string (slug)
        string $reason = 'automatic',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->oldLevel = $oldLevel;
        $this->newLevel = $newLevel;
        $this->reason = $reason;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'old_level' => $oldLevel,
            'new_level' => $newLevel,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
