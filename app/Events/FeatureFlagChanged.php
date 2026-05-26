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

    public function __construct(
        public readonly string $featureName,
        public readonly string $action,
        public readonly array $oldValues = [],
        public readonly array $newValues = [],
        public readonly ?int $changedBy = null,
        public readonly \DateTime $changedAt = new \DateTime()
    ) {
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
