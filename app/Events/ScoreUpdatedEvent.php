<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class ScoreUpdatedEvent extends Event
{
    public int $userId;
    public float $oldScore;
    public float $newScore;
    public string $reason;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        float $oldScore,
        float $newScore,
        string $reason = '',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->oldScore = $oldScore;
        $this->newScore = $newScore;
        $this->reason = $reason;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'old_score' => $oldScore,
            'new_score' => $newScore,
            'reason' => $reason,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
