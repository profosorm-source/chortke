<?php

declare(strict_types=1);

namespace App\Services\Health;

use Core\Database;
use Core\Cache;

class HealthCheckService
{
    public function __construct() {}

    /**
     * Liveness Probe: Indicates if the application is running at all.
     * Fast and lightweight, doesn't depend on external services like DB.
     */
    public function checkLiveness(): array
    {
        $status = 'ok';
        $checks = [];

        // Check PHP Memory Availability
        $memoryThreshold = (int)config('health.thresholds.memory_usage', 85);
        $systemMemoryPercent = $this->getSystemMemoryUsagePercent();

        if ($systemMemoryPercent !== null) {
            $checks['memory'] = [
                'status' => $systemMemoryPercent < $memoryThreshold ? 'ok' : 'error',
                'usage_percent' => $systemMemoryPercent,
                'threshold_percent' => $memoryThreshold
            ];
            
            if ($systemMemoryPercent >= $memoryThreshold) {
                $status = 'error';
            }
        } else {
            // Fallback to process memory
            $checks['memory'] = [
                'status' => 'ok',
                'process_memory_mb' => round(memory_get_usage(true) / (1024 * 1024), 2),
                'process_memory_peak_mb' => round(memory_get_peak_usage(true) / (1024 * 1024), 2)
            ];
        }

        return [
            'status' => $status,
            'timestamp' => date('c'),
            'checks' => $checks
        ];
    }

    /**
     * Readiness Probe: Indicates if the node is ready to accept traffic.
     * Probes DB, Redis, Queues, Disk, Circuit Breakers, and External Gateways.
     */
    public function checkReadiness(): array
    {
        $checks = [];
        $status = 'ok';

        $checks['database'] = $this->probeDatabase();
        if (in_array($checks['database']['status'], ['error', 'degraded'], true)) {
            $status = $checks['database']['status'];
        }

        $checks['redis'] = $this->probeRedis();
        if ($checks['redis']['status'] === 'error') {
            $status = 'error';
        }

        $checks['queue'] = $this->probeQueue();
        if ($checks['queue']['status'] === 'degraded' && $status === 'ok') {
            $status = 'degraded'; // Queue issues shouldn't 503 the API usually, but marks degraded
        }

        $checks['disk'] = $this->probeDisk();
        if ($checks['disk']['status'] === 'error') {
            $status = 'error';
        }

        $checks['circuit_breakers'] = $this->probeCircuitBreakers();
        if ($checks['circuit_breakers']['status'] === 'degraded' && $status === 'ok') {
            $status = 'degraded';
        }

        $checks['external_gateways'] = $this->probeExternalGateways();
        if ($checks['external_gateways']['status'] === 'degraded' && $status === 'ok') {
            $status = 'degraded';
        }

        return [
            'status' => $status,
            'timestamp' => date('c'),
            'checks' => $checks
        ];
    }

    private function probeDatabase(): array
    {
        try {
            $db = \Core\Container::getInstance()->make(\Core\Database::class);
            $pdo = $db->getPdo();
            $startTime = microtime(true);
            $pdo->query("SELECT 1");
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            
            $dbReplLag = null;
            $dbReplConfigured = false;
            try {
                $replStatus = $pdo->query("SHOW REPLICA STATUS")->fetch(\PDO::FETCH_ASSOC);
                if (!$replStatus) {
                    $replStatus = $pdo->query("SHOW SLAVE STATUS")->fetch(\PDO::FETCH_ASSOC);
                }
                if ($replStatus) {
                    $dbReplConfigured = true;
                    $dbReplLag = isset($replStatus['Seconds_Behind_Source']) 
                        ? (int)$replStatus['Seconds_Behind_Source'] 
                        : ($replStatus['Seconds_Behind_Master'] ?? null);
                }
            } catch (\Throwable) {}

            $res = [
                'status' => 'ok',
                'latency_ms' => $latency,
                'replication' => [
                    'configured' => $dbReplConfigured,
                    'lag_seconds' => $dbReplLag
                ]
            ];

            if ($dbReplLag !== null && $dbReplLag > 30) {
                $res['status'] = 'degraded';
                $res['message'] = "Replication lag is high: {$dbReplLag}s";
            }

            return $res;
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Database query failed: ' . $e->getMessage()
            ];
        }
    }

    private function probeRedis(): array
    {
        $redisEnabled = config('redis.enabled', true);
        if (!$redisEnabled || !class_exists('Redis')) {
            return ['status' => 'disabled', 'driver' => 'file'];
        }

        try {
            $redisHost = config('redis.host', '127.0.0.1');
            $redisPort = (int)config('redis.port', 6379);
            $redisPass = config('redis.password');
            $redisTimeout = (float)config('redis.timeout', 1.0);
            
            $redis = new \Redis();
            $redis->connect($redisHost, $redisPort, $redisTimeout);
            if ($redisPass) {
                $redis->auth($redisPass);
            }
            
            $startTime = microtime(true);
            $redis->set('health_check_temp', '1', 5);
            $redis->get('health_check_temp');
            $redis->del('health_check_temp');
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'status' => 'ok',
                'driver' => 'redis',
                'latency_ms' => $latency
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Redis connection failed: ' . $e->getMessage()
            ];
        }
    }

    private function probeQueue(): array
    {
        try {
            $db = \Core\Container::getInstance()->make(\Core\Database::class);
            $pdo = $db->getPdo();
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('queues', $tables)) {
                return ['status' => 'disabled', 'message' => 'Queues table does not exist'];
            }

            $hasFailedTable = in_array('failed_jobs', $tables);
            
            $pendingJobs = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE reserved_at IS NULL AND available_at <= NOW()")->fetchColumn();
            $runningJobs = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE reserved_at IS NOT NULL")->fetchColumn();
            $stuckJobs = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE attempts >= 3")->fetchColumn();
            $failedJobs = $hasFailedTable ? (int)$pdo->query("SELECT COUNT(*) FROM failed_jobs")->fetchColumn() : 0;
            
            $res = [
                'status' => 'ok',
                'pending_jobs' => $pendingJobs,
                'running_jobs' => $runningJobs,
                'stuck_jobs' => $stuckJobs,
                'failed_jobs' => $failedJobs
            ];
            
            if ($stuckJobs > 5 || $failedJobs > 20) {
                $res['status'] = 'degraded';
                $res['message'] = 'High number of stuck or failed jobs in queue';
            }

            return $res;
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Queue check failed: ' . $e->getMessage()];
        }
    }

    private function probeCircuitBreakers(): array
    {
        try {
            $cache = \Core\Container::getInstance()->make(\Core\Cache::class);
            $servicesToCheck = ['tronscan', 'bscscan', 'sms', 'fcm'];
            $circuitBreakers = [];
            $anyOpen = false;
            
            foreach ($servicesToCheck as $service) {
                $stateKey = "circuit_breaker:{$service}:state";
                $state = $cache->get($stateKey) ?: ['status' => 'closed', 'failures' => 0, 'opened_at' => null];
                
                $circuitBreakers[$service] = [
                    'status' => $state['status'] ?? 'closed',
                    'failures' => $state['failures'] ?? 0,
                    'opened_at' => isset($state['opened_at']) ? date('c', (int)$state['opened_at']) : null
                ];
                
                if (($state['status'] ?? 'closed') === 'open') {
                    $anyOpen = true;
                }
            }
            
            return [
                'status' => $anyOpen ? 'degraded' : 'ok',
                'services' => $circuitBreakers
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Circuit breaker check failed: ' . $e->getMessage()];
        }
    }

    private function probeDisk(): array
    {
        try {
            $storagePath = dirname(__DIR__, 3) . '/storage';
            if (!is_dir($storagePath)) {
                return ['status' => 'unknown', 'message' => 'Storage path not found'];
            }
            
            $diskThreshold = (int)config('health.thresholds.disk_usage', 90);
            
            $diskTotal = @disk_total_space($storagePath);
            $diskFree = @disk_free_space($storagePath);
            
            if ($diskTotal !== false && $diskFree !== false && $diskTotal > 0) {
                $diskUsed = $diskTotal - $diskFree;
                $diskUsagePercent = round(($diskUsed / $diskTotal) * 100, 2);
                
                $res = [
                    'status' => $diskUsagePercent < $diskThreshold ? 'ok' : 'error',
                    'total_gb' => round($diskTotal / (1024 * 1024 * 1024), 2),
                    'used_gb' => round($diskUsed / (1024 * 1024 * 1024), 2),
                    'free_gb' => round($diskFree / (1024 * 1024 * 1024), 2),
                    'usage_percent' => $diskUsagePercent,
                    'threshold_percent' => $diskThreshold
                ];
                return $res;
            }
            return ['status' => 'unknown', 'message' => 'Unable to read disk space'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Disk check failed: ' . $e->getMessage()];
        }
    }

    private function probeExternalGateways(): array
    {
        // Simple HTTP HEAD ping to common endpoints
        $urls = [
            'zarinpal' => 'https://api.zarinpal.com',
            'kavenegar_sms' => 'https://api.kavenegar.com',
            'tronscan' => 'https://apilist.tronscanapi.com/api'
        ];

        $results = [];
        $anyDown = false;

        foreach ($urls as $name => $url) {
            try {
                $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 1.5]]);
                $startTime = microtime(true);
                $response = @file_get_contents($url, false, $ctx);
                $latency = round((microtime(true) - $startTime) * 1000, 2);

                if ($response !== false || (isset($http_response_header) && count($http_response_header) > 0)) {
                    $results[$name] = ['status' => 'up', 'latency_ms' => $latency];
                } else {
                    $results[$name] = ['status' => 'down', 'latency_ms' => null];
                    $anyDown = true;
                }
            } catch (\Throwable $e) {
                $results[$name] = ['status' => 'down', 'error' => $e->getMessage()];
                $anyDown = true;
            }
        }

        return [
            'status' => $anyDown ? 'degraded' : 'ok',
            'services' => $results
        ];
    }

    private function getSystemMemoryUsagePercent(): ?float
    {
        if (stristr(PHP_OS, 'WIN')) {
            $output = [];
            @exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value', $output);
            if (!empty($output)) {
                $free = 0;
                $total = 0;
                foreach ($output as $line) {
                    if (strpos($line, 'FreePhysicalMemory') !== false) {
                        $free = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                    }
                    if (strpos($line, 'TotalVisibleMemorySize') !== false) {
                        $total = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                    }
                }
                if ($total > 0) {
                    return round((($total - $free) / $total) * 100, 2);
                }
            }
        } else {
            if (file_exists('/proc/meminfo')) {
                $meminfo = @file_get_contents('/proc/meminfo');
                if ($meminfo) {
                    preg_match('/MemTotal:\s+(\d+) kB/', $meminfo, $matchesTotal);
                    preg_match('/MemAvailable:\s+(\d+) kB/', $meminfo, $matchesAvailable);
                    if (isset($matchesTotal[1]) && isset($matchesAvailable[1])) {
                        $total = (int)$matchesTotal[1];
                        $available = (int)$matchesAvailable[1];
                        return round((($total - $available) / $total) * 100, 2);
                    }
                }
            }
        }
        return null;
    }
}
