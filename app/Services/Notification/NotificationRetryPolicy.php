<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\LoggerInterface;
use Core\Cache;

/**
 * Channel-specific retry policy + lightweight circuit breaker for notification gateways.
 */
class NotificationRetryPolicy
{
    private array $policies = [
        'fcm' => ['attempts' => 3, 'sleep_ms' => 200, 'circuit_failures' => 5, 'circuit_seconds' => 60],
        'push' => ['attempts' => 2, 'sleep_ms' => 200, 'circuit_failures' => 5, 'circuit_seconds' => 60],
        'sms' => ['attempts' => 2, 'sleep_ms' => 500, 'circuit_failures' => 3, 'circuit_seconds' => 120],
        'email' => ['attempts' => 3, 'sleep_ms' => 1000, 'circuit_failures' => 5, 'circuit_seconds' => 120],
        'log' => ['attempts' => 1, 'sleep_ms' => 0, 'circuit_failures' => 1000, 'circuit_seconds' => 1],
    ];

    public function __construct(
        private Cache $cache,
        private LoggerInterface $logger
    ) {}

    public function execute(string $channel, callable $operation): bool
    {
        $channel = strtolower(trim($channel));
        $policy = $this->policies[$channel] ?? ['attempts' => 1, 'sleep_ms' => 0, 'circuit_failures' => 5, 'circuit_seconds' => 60];

        if ($this->isCircuitOpen($channel)) {
            $this->logger->warning('notif.circuit_open_skip', ['channel' => $channel]);
            return false;
        }

        $last = null;
        for ($attempt = 1; $attempt <= (int) $policy['attempts']; $attempt++) {
            try {
                $result = (bool) $operation();
                if ($result) {
                    $this->resetFailures($channel);
                    return true;
                }

                $last = new \RuntimeException('Notification channel returned false.');
            } catch (\Throwable $e) {
                $last = $e;
            }

            $this->logger->warning('notif.dispatch_attempt_failed', [
                'channel' => $channel,
                'attempt' => $attempt,
                'error' => $last?->getMessage(),
            ]);

            if ($attempt < (int) $policy['attempts'] && (int) $policy['sleep_ms'] > 0) {
                usleep((int) $policy['sleep_ms'] * 1000);
            }
        }

        $this->recordFailure($channel, (int) $policy['circuit_failures'], (int) $policy['circuit_seconds']);
        return false;
    }

    private function isCircuitOpen(string $channel): bool
    {
        return (bool) $this->cache->get("notif_circuit_open:{$channel}", false);
    }

    private function recordFailure(string $channel, int $threshold, int $openSeconds): void
    {
        try {
            $count = $this->cache->increment("notif_failures:{$channel}", 1, max(60, $openSeconds));
            if ($count !== false && (int) $count >= $threshold) {
                $this->cache->setSeconds("notif_circuit_open:{$channel}", true, $openSeconds);
                $this->logger->error('notif.circuit_opened', [
                    'channel' => $channel,
                    'failures' => (int) $count,
                    'seconds' => $openSeconds,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('notif.circuit_record_failed', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resetFailures(string $channel): void
    {
        $this->cache->forget("notif_failures:{$channel}");
        $this->cache->forget("notif_circuit_open:{$channel}");
    }
}
