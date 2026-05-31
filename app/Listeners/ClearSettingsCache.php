<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SettingsUpdated;
use Core\Cache;
use App\Services\Settings\AppSettings;

class ClearSettingsCache
{
    private Cache $cache;
    private AppSettings $appSettings;

    public function __construct(Cache $cache, AppSettings $appSettings)
    {
        $this->cache = $cache;
        $this->appSettings = $appSettings;
    }

    public function handle(SettingsUpdated $event): void
    {
        // 1. Clear Distributed/Redis cache
        $this->cache->forget('system:settings:v2');
        
        // 2. Clear Local Instance Cache
        $this->appSettings->clearInstanceCache();

        // 3. Clear legacy global config if needed
        if (function_exists('config_reload')) {
            config_reload();
        }
    }
}
