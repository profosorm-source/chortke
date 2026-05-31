<?php

declare(strict_types=1);

namespace App\Listeners;

use Core\Container;
use Core\Cache;
use App\Contracts\LoggerInterface;

class CacheInvalidateListener
{
    public function __construct(
        private Cache $cache,
        private LoggerInterface $logger
    ) {
    }

    public function handle($event)
    {
        try {
            $key = null;
            if ($event instanceof \Core\Event) {
                $data = $event->getData();
                $key = $data['key'] ?? null;
            } elseif (is_array($event)) {
                $key = $event['key'] ?? null;
            }

            if (!empty($key)) {
                $this->cache->forget($key);
                $this->logger->info('cache.invalidate.event', ['key' => $key]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('cache.invalidate.error', ['message' => $e->getMessage()]);
        }
    }
}
