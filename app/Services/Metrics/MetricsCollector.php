<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Contracts\MetricsCollectorInterface;
use App\Contracts\LoggerInterface;
use Core\Cache;

class MetricsCollector implements MetricsCollectorInterface
{
    private ?Cache $cache = null;
public function __construct(LoggerInterface $logger, ?Cache $cache = null)
    {
        $this->logger = $logger;
        $this->cache  = $cache;
    }

    public function increment(string $metric, array $tags = []): void
    {
        $this->logger->info("metric.increment", ['metric' => $metric, 'tags' => $tags]);

        if ($this->cache) {
            try {
                $key = "metrics:count:{$metric}";
                $current = (int)$this->cache->get($key);
                $this->cache->putSeconds($key, $current + 1, 86400 * 30);
            } catch (\Throwable $e) {
            }
        }
    }

    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $this->logger->info("metric.gauge", ['metric' => $metric, 'value' => $value, 'tags' => $tags]);

        if ($this->cache) {
            try {
                $key = "metrics:gauge:{$metric}";
                $this->cache->putSeconds($key, $value, 86400 * 30);
            } catch (\Throwable $e) {
            }
        }
    }

    public function timing(string $metric, float $seconds, array $tags = []): void
    {
        $this->logger->info("metric.timing", ['metric' => $metric, 'seconds' => $seconds, 'tags' => $tags]);

        if ($this->cache) {
            try {
                $key = "metrics:timing:{$metric}:list";
                $current = $this->cache->get($key);
                $list = $current ? json_decode($current, true) : [];
                $list[] = [
                    'val' => $seconds,
                    't' => time()
                ];
                if (count($list) > 100) {
                    array_shift($list);
                }
                $this->cache->putSeconds($key, json_encode($list), 86400 * 30);
            } catch (\Throwable $e) {
            }
        }
    }
}

