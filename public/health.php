<?php

declare(strict_types=1);

/**
 * Chortke Premium Health Check Endpoint
 *
 * Checks database, replication lag, Redis latency, DB-based queue health,
 * circuit breaker statuses, disk usage, and system memory load.
 */

header('Content-Type: application/json; charset=utf-8');

$baseDir = dirname(__DIR__);
$status = 'healthy';
$checks = [];

// ── ۱. Safe Bootstrapping of Core Application ─────────────────────
try {
    require_once $baseDir . '/vendor/autoload.php';
    require_once $baseDir . '/bootstrap/app.php';
    
    $container = \Core\Container::getInstance();
    $database = $container->make(\Core\Database::class);
    $cache = \Core\Cache::getInstance();
    
    $allowedIps = config('health.allowed_ips', ['127.0.0.1', '::1']);
    $token = config('health.check_token', '');
    
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $requestToken = $_GET['token'] ?? $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '';
    
    $isIpAllowed = in_array($clientIp, $allowedIps, true);
    $isTokenValid = !empty($token) && hash_equals($token, $requestToken);
    
    if (!$isIpAllowed && !$isTokenValid) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'unhealthy',
        'timestamp' => date('c'),
        'checks' => [
            'bootstrap' => [
                'status' => 'error',
                'message' => 'Application bootstrapping failed: ' . $e->getMessage()
            ]
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── ۲. Database & Replication Lag Check ───────────────────────────
try {
    $pdo = $database->getPdo();
    $startTime = microtime(true);
    $pdo->query("SELECT 1");
    $dbLatency = round((microtime(true) - $startTime) * 1000, 2);
    
    // Check replication lag
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
                : (isset($replStatus['Seconds_Behind_Master']) ? (int)$replStatus['Seconds_Behind_Master'] : null);
        }
    } catch (\Throwable) {
        // Safe to ignore if standalone / no permissions
    }
    
    $checks['database'] = [
        'status' => 'ok',
        'latency_ms' => $dbLatency,
        'replication' => [
            'configured' => $dbReplConfigured,
            'lag_seconds' => $dbReplLag
        ]
    ];
    
    if ($dbReplLag !== null && $dbReplLag > 30) {
        $status = 'degraded';
        $checks['database']['status'] = 'degraded';
        $checks['database']['message'] = 'Database replication lag is high: ' . $dbReplLag . ' seconds';
    }
} catch (\Throwable $e) {
    $status = 'unhealthy';
    $checks['database'] = [
        'status' => 'error',
        'message' => 'Database query failed: ' . $e->getMessage()
    ];
}

// ── ۳. Redis Connectivity & Latency Check ────────────────────────
$redisEnabled = config('redis.enabled', true);
if ($redisEnabled && class_exists('Redis')) {
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
        $redisLatency = round((microtime(true) - $startTime) * 1000, 2);
        
        $checks['redis'] = [
            'status' => 'ok',
            'driver' => $cache->driver(),
            'latency_ms' => $redisLatency
        ];
    } catch (\Throwable $e) {
        $status = 'unhealthy';
        $checks['redis'] = [
            'status' => 'error',
            'message' => 'Redis connection/write failed: ' . $e->getMessage()
        ];
    }
} else {
    $checks['redis'] = [
        'status' => 'disabled',
        'driver' => $cache->driver()
    ];
}

// ── ۴. DB Queue Health Check ──────────────────────────────────────
try {
    // Check if table queues exists
    $tableQueuesExists = false;
    $tableFailedExists = false;
    
    $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if ($t === 'queues') {
            $tableQueuesExists = true;
        }
        if ($t === 'failed_jobs') {
            $tableFailedExists = true;
        }
    }
    
    if ($tableQueuesExists) {
        $pendingJobs = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE reserved_at IS NULL AND available_at <= NOW()")->fetchColumn();
        $runningJobs = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE reserved_at IS NOT NULL")->fetchColumn();
        $stuckJobs = (int)$pdo->query("SELECT COUNT(*) FROM queues WHERE attempts >= 3")->fetchColumn();
        $failedJobs = $tableFailedExists ? (int)$pdo->query("SELECT COUNT(*) FROM failed_jobs")->fetchColumn() : 0;
        
        $checks['queue'] = [
            'status' => 'ok',
            'pending_jobs' => $pendingJobs,
            'running_jobs' => $runningJobs,
            'stuck_jobs' => $stuckJobs,
            'failed_jobs' => $failedJobs
        ];
        
        if ($stuckJobs > 5 || $failedJobs > 20) {
            $status = 'degraded';
            $checks['queue']['status'] = 'degraded';
            $checks['queue']['message'] = 'High number of stuck or failed jobs in queue';
        }
    } else {
        $checks['queue'] = [
            'status' => 'disabled',
            'message' => 'Queues table does not exist'
        ];
    }
} catch (\Throwable $e) {
    $checks['queue'] = [
        'status' => 'error',
        'message' => 'Queue health check failed: ' . $e->getMessage()
    ];
}

// ── ۵. Circuit Breakers Check ─────────────────────────────────────
try {
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
    
    $checks['circuit_breakers'] = [
        'status' => $anyOpen ? 'degraded' : 'ok',
        'services' => $circuitBreakers
    ];
    
    if ($anyOpen) {
        $status = 'degraded';
    }
} catch (\Throwable $e) {
    $checks['circuit_breakers'] = [
        'status' => 'error',
        'message' => 'Circuit breaker check failed: ' . $e->getMessage()
    ];
}

// ── ۶. Disk Usage Check ───────────────────────────────────────────
try {
    $storagePath = $baseDir . '/storage';
    $diskThreshold = (int)config('health.thresholds.disk_usage', 90);
    
    $diskTotal = @disk_total_space($storagePath);
    $diskFree = @disk_free_space($storagePath);
    
    if ($diskTotal !== false && $diskFree !== false) {
        $diskUsed = $diskTotal - $diskFree;
        $diskUsagePercent = round(($diskUsed / $diskTotal) * 100, 2);
        
        $checks['disk'] = [
            'status' => $diskUsagePercent < $diskThreshold ? 'ok' : 'error',
            'total_gb' => round($diskTotal / (1024 * 1024 * 1024), 2),
            'used_gb' => round($diskUsed / (1024 * 1024 * 1024), 2),
            'free_gb' => round($diskFree / (1024 * 1024 * 1024), 2),
            'usage_percent' => $diskUsagePercent,
            'threshold_percent' => $diskThreshold
        ];
        
        if ($diskUsagePercent >= $diskThreshold) {
            $status = 'unhealthy';
        }
    } else {
        $checks['disk'] = [
            'status' => 'unknown',
            'message' => 'Unable to read disk space details'
        ];
    }
} catch (\Throwable $e) {
    $checks['disk'] = [
        'status' => 'error',
        'message' => 'Disk check failed: ' . $e->getMessage()
    ];
}

// ── ۷. System Memory Check ────────────────────────────────────────
try {
    $memoryThreshold = (int)config('health.thresholds.memory_usage', 85);
    $systemMemoryPercent = null;
    
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
                $systemMemoryPercent = round((($total - $free) / $total) * 100, 2);
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
                    $systemMemoryPercent = round((($total - $available) / $total) * 100, 2);
                }
            }
        }
    }
    
    if ($systemMemoryPercent !== null) {
        $checks['memory'] = [
            'status' => $systemMemoryPercent < $memoryThreshold ? 'ok' : 'error',
            'usage_percent' => $systemMemoryPercent,
            'threshold_percent' => $memoryThreshold
        ];
        
        if ($systemMemoryPercent >= $memoryThreshold) {
            $status = 'unhealthy';
        }
    } else {
        // Fallback to process memory
        $checks['memory'] = [
            'status' => 'ok',
            'process_memory_mb' => round(memory_get_usage(true) / (1024 * 1024), 2),
            'process_memory_peak_mb' => round(memory_get_peak_usage(true) / (1024 * 1024), 2)
        ];
    }
} catch (\Throwable $e) {
    $checks['memory'] = [
        'status' => 'error',
        'message' => 'Memory check failed: ' . $e->getMessage()
    ];
}

// ── ۸. Output Result ──────────────────────────────────────────────
$response = [
    'status' => $status,
    'timestamp' => date('c'),
    'checks' => $checks
];

http_response_code($status === 'unhealthy' ? 503 : 200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);