<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

/**
 * CriticalFeatureChangedEvent - زمانی که یک فیچر Critical تغییر کند
 * 
 * MED-09: Listeners می‌توانند مستقل از Listener اصلی handle کنند
 */
class CriticalFeatureChangedEvent extends Event
{
    public function __construct(
        public readonly string $featureName,
        public readonly string $action,
        public readonly ?\DateTime $changedAt = null,
        public readonly ?int $changedBy = null,
        public readonly array $changes = []
    ) {
        parent::__construct();
    }
}
