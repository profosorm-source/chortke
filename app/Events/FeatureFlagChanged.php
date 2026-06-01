<?php

declare(strict_types=1);

namespace App\Events;

use Core\Event;

/**
 * Event که زمانی که یک Feature Flag تغییر می‌کند، dispatch می‌شود
 * MED-15 Fix: برقراری انطباق با نیازمندی EventDispatcher جهت جلوگیری از بروز TypeError در Listeners
 */
class FeatureFlagChanged extends Event
{
    private const VALID_ACTIONS = ['toggled', 'updated', 'created', 'deleted'];

    public string $featureName;
    public string $action;
    public array $oldValues;
    public array $newValues;
    public ?int $changedBy;
    public \DateTime $changedAt;
    public function __construct(
        string $featureName,
        string $action,
        array $oldValues = [],
        array $newValues = [],
        ?int $changedBy = null,
        \DateTime $changedAt = new \DateTime()
    ) {        $this->featureName = $featureName;
        $this->action = $action;
        $this->oldValues = $oldValues;
        $this->newValues = $newValues;
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;

        if (!in_array($this->action, self::VALID_ACTIONS, true)) {
            throw new \InvalidArgumentException("Invalid action: {$this->action}");
        }
        
        // تغذیه دیتای پایه برای کلاس والد (مفید در سناریوهای Logging عمومی و پردازش Async)
        parent::__construct([
            'feature_name' => $this->featureName,
            'action'       => $this->action,
            'old_values'   => $this->oldValues,
            'new_values'   => $this->newValues,
            'changed_by'   => $this->changedBy,
            'changed_at'   => $this->changedAt->format(\DateTime::ATOM),
        ]);
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
