<?php

declare(strict_types=1);

namespace App\Events;

class ScoreDeltaAppendedEvent
{
    public function __construct(
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly string $domain,
        public readonly float $delta,
        public readonly string $source
    ) {}
}
