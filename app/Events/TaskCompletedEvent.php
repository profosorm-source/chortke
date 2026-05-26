<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class TaskCompletedEvent extends Event
{
    public function __construct(
        public readonly int $userId,
        public readonly int $taskId,
        public readonly float $xp = 0.0,
        public readonly string $context = '',
        public readonly \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {
        parent::__construct([
            'user_id' => $userId,
            'task_id' => $taskId,
            'xp' => $xp,
            'context' => $context,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
