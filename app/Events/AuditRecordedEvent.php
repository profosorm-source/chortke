<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

class AuditRecordedEvent extends Event
{
    public string $eventName;
    public ?int $userId;
    public array $context;
    public ?int $actorId;

    public function __construct(string $eventName, ?int $userId = null, array $context = [], ?int $actorId = null)
    {
        parent::__construct();
        $this->eventName = $eventName;
        $this->userId = $userId;
        $this->context = $context;
        $this->actorId = $actorId;
    }
}
