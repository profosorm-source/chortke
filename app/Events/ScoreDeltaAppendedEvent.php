<?php

declare(strict_types=1);

namespace App\Events;

class ScoreDeltaAppendedEvent
{
    public string $entityType;
    public int $entityId;
    public string $domain;
    public float $delta;
    public string $source;
    public function __construct(
        string $entityType,
        int $entityId,
        string $domain,
        float $delta,
        string $source
    ) {        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->domain = $domain;
        $this->delta = $delta;
        $this->source = $source;
}
}
