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
    public string $featureName;
    public string $action;
    public ?\DateTime $changedAt;
    public ?int $changedBy;
    public array $changes;
    public function __construct(
        string $featureName,
        string $action,
        ?\DateTime $changedAt = null,
        ?int $changedBy = null,
        array $changes = []
    ) {        $this->featureName = $featureName;
        $this->action = $action;
        $this->changedAt = $changedAt;
        $this->changedBy = $changedBy;
        $this->changes = $changes;

        // MED-16 Fix: پاس‌دادن دیتاها به سازنده والد برای فعال شدن عملکرد $event->getData() در پردازش‌های جانبی
        parent::__construct([
            'feature_name' => $this->featureName,
            'action'       => $this->action,
            'changed_at'   => $this->changedAt?->format(\DateTime::ATOM),
            'changed_by'   => $this->changedBy,
            'changes'      => $this->changes
        ]);
    }
}
