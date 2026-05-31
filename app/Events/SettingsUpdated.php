<?php

declare(strict_types=1);

namespace App\Events;

class SettingsUpdated
{
    public array $changedKeys;

    public function __construct(array $changedKeys = [])
    {
        $this->changedKeys = $changedKeys;
    }
}
