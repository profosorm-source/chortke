<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class TaskCompletedEvent extends Event
{
    public int $userId;
    public int $taskId;
    public float $xp;
    public string $context;
    public \DateTimeInterface $occurredAt;
    public function __construct(
        int $userId,
        int $taskId,
        float $xp = 0.0,
        string $context = '',
        \DateTimeInterface $occurredAt = new \DateTimeImmutable()
    ) {        $this->userId = $userId;
        $this->taskId = $taskId;
        $this->xp = $xp;
        $this->context = $context;
        $this->occurredAt = $occurredAt;

        parent::__construct([
            'user_id' => $userId,
            'task_id' => $taskId,
            'xp' => $xp,
            'context' => $context,
            'occurred_at' => $occurredAt->format(\DateTime::ATOM)
        ]);
    }
}
