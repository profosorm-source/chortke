<?php

namespace App\Events;

/**
 * Event که زمانی که یک Feature Flag تغییر می‌کند، dispatch می‌شود
 */
class FeatureFlagChanged
{
    public string $featureName;
    public string $action;  // 'toggled', 'updated', 'created', 'deleted'
    public array $oldValues;
    public array $newValues;
    public ?int $changedBy;
    public \DateTime $changedAt;
    
    public function __construct(
        string $featureName,
        string $action,
        array $oldValues = [],
        array $newValues = [],
        ?int $changedBy = null
    ) {
        $this->featureName = $featureName;
        $this->action = $action;
        $this->oldValues = $oldValues;
        $this->newValues = $newValues;
        $this->changedBy = $changedBy;
        $this->changedAt = new \DateTime();
    }
    
    /**
     * دریافت تغییرات به صورت Array
     */
    public function getChanges(): array
    {
        $changes = [];
        
        foreach ($this->newValues as $key => $newValue) {
            $oldValue = $this->oldValues[$key] ?? null;
            
            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }
        
        return $changes;
    }
    
    /**
     * آیا فیچر فعال شده؟
     */
    public function wasEnabled(): bool
    {
        return $this->action === 'toggled' 
            && ($this->oldValues['enabled'] ?? false) === false
            && ($this->newValues['enabled'] ?? false) === true;
    }
    
    /**
     * آیا فیچر غیرفعال شده؟
     */
    public function wasDisabled(): bool
    {
        return $this->action === 'toggled' 
            && ($this->oldValues['enabled'] ?? false) === true
            && ($this->newValues['enabled'] ?? false) === false;
    }
}
