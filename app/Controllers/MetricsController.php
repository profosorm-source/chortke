<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\LoggerInterface;
use App\Services\Shared\DashboardStatsService;

/**
 * MetricsController - Exposes Prometheus metrics
 */
class MetricsController extends \App\Controllers\BaseController
{
    private DashboardStatsService $metricsService;

    public function __construct(LoggerInterface $logger, DashboardStatsService $metricsService)
    {
        parent::__construct($logger);
        $this->metricsService = $metricsService;
    }

    public function metrics(): void
    {
        // 1. IP Whitelisting / Token Authorization
        $allowedIps = config('health.allowed_ips', ['127.0.0.1', '::1']);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $token = $_GET['token'] ?? '';
        $expectedToken = config('health.check_token');

        $isIpAllowed = in_array($clientIp, $allowedIps, true);
        $isTokenValid = !empty($expectedToken) && hash_equals((string)$expectedToken, (string)$token);

        if (!$isIpAllowed && !$isTokenValid) {
            http_response_code(403);
            exit('Forbidden');
        }

        header('Content-Type: text/plain; charset=utf-8');

        // Total HTTP Requests
        $requestsCount = $this->metricsService->getRecentRequestsCount(5);
        
        echo "# HELP app_requests_total Total HTTP requests in last 5 min\n";
        echo "# TYPE app_requests_total counter\n";
        echo "app_requests_total " . $requestsCount . "\n";

        // Application Errors
        $errorCount = $this->metricsService->getRecentErrorsCount('error', 5);
        $criticalCount = $this->metricsService->getRecentErrorsCount('critical', 5);

        echo "# HELP app_errors_total Application errors\n";
        echo "# TYPE app_errors_total counter\n";
        echo "app_errors_total{level=\"error\"} " . $errorCount . "\n";
        echo "app_errors_total{level=\"critical\"} " . $criticalCount . "\n";

        // Active Users
        $activeUsers = $this->metricsService->getActiveUsersCount();
        
        echo "# HELP app_active_users Current active users\n";
        echo "# TYPE app_active_users gauge\n";
        echo "app_active_users " . $activeUsers . "\n";

        // DB Status
        echo "# HELP app_db_up Database availability\n";
        echo "# TYPE app_db_up gauge\n";
        
        if ($this->metricsService->isDatabaseUp()) {
            echo "app_db_up 1\n";
        } else {
            echo "app_db_up 0\n";
        }
    }

    /**
     * Comprehensive System Health Check
     * Validates infrastructure dependencies and returns JSON status
     */
    public function health(): void
    {
        // IP/Token auth
        $allowedIps = config('health.allowed_ips', ['127.0.0.1', '::1']);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $token = $_GET['token'] ?? '';
        $expectedToken = config('health.check_token');

        $isIpAllowed = in_array($clientIp, $allowedIps, true);
        $isTokenValid = !empty($expectedToken) && hash_equals((string)$expectedToken, (string)$token);

        if (!$isIpAllowed && !$isTokenValid) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');

        $health = [
            'status' => 'pass',
            'timestamp' => date('Y-m-d\TH:i:sP'),
            'checks' => [],
            'metrics' => []
        ];

        // 1. Database
        $dbStatus = $this->metricsService->isDatabaseUp() ? 'pass' : 'fail';
        $health['checks']['database'] = ['status' => $dbStatus];
        if ($dbStatus === 'fail') $health['status'] = 'fail';

        // 2. Redis
        $redisStatus = 'fail';
        try {
            $redis = app(\Core\Redis::class);
            if ($redis->isAvailable()) {
                $redis->getClient()->ping();
                $redisStatus = 'pass';
            }
        } catch (\Throwable $e) {
            $health['checks']['redis']['error'] = $e->getMessage();
        }
        $health['checks']['redis']['status'] = $redisStatus;

        // 3. Queue Depth & DLQ
        $queueStatus = 'pass';
        try {
            $queue = app(\Core\Queue::class);
            $report = $queue->getQueueStatusReport();
            
            $totalPending = 0;
            $totalFailed = 0;
            foreach ($report as $q => $stats) {
                $totalPending += $stats['pending'];
                $totalFailed += $stats['failed'];
            }
            
            $health['metrics']['queue'] = [
                'total_pending' => $totalPending,
                'total_failed' => $totalFailed,
                'details' => $report
            ];

            // Alerting integration: if DLQ is accumulating too fast
            if ($totalFailed > 100) {
                $queueStatus = 'warn';
                $health['checks']['queue']['message'] = 'High number of jobs in Dead Letter Queue (DLQ)';
                // Integrates with Sentry / Logger
                $this->logger->critical('dlq.accumulation.alert', ['total_failed' => $totalFailed]);
            }
        } catch (\Throwable $e) {
            $queueStatus = 'fail';
            $health['checks']['queue']['error'] = $e->getMessage();
        }
        $health['checks']['queue']['status'] = $queueStatus;

        // 4. Disk Space Check
        $diskStatus = 'pass';
        try {
            $diskFree = disk_free_space('/');
            $diskTotal = disk_total_space('/');
            if ($diskFree !== false && $diskTotal !== false) {
                $freePercentage = ($diskFree / $diskTotal) * 100;
                $health['metrics']['disk'] = [
                    'free_bytes' => $diskFree,
                    'total_bytes' => $diskTotal,
                    'free_percentage' => round($freePercentage, 2)
                ];
                
                if ($freePercentage < 10) {
                    $diskStatus = 'warn';
                    $health['checks']['disk']['message'] = 'Low disk space (< 10%)';
                    $this->logger->critical('system.disk.low_space', ['free_percentage' => round($freePercentage, 2)]);
                }
            }
        } catch (\Throwable $e) {}
        $health['checks']['disk']['status'] = $diskStatus;

        // 5. External Services Health & Circuit Breakers
        try {
            $cb = app(\Core\CircuitBreaker::class);
            // Get states of registered services in circuit breaker
            $cbStates = [];
            foreach (['sms_gateway', 'fcm', 'payment_gateway', 'bank_api'] as $service) {
                if ($cb->isOpen($service)) {
                    $cbStates[$service] = 'OPEN (Failing)';
                    if ($health['status'] === 'pass') {
                        $health['status'] = 'warn'; // degrade overall health
                    }
                } else {
                    $cbStates[$service] = 'CLOSED (Healthy)';
                }
            }
            $health['checks']['circuit_breakers'] = [
                'status' => 'pass',
                'states' => $cbStates
            ];
        } catch (\Throwable $e) {}

        // Overall HTTP status code based on health
        if ($health['status'] === 'fail') {
            http_response_code(503);
        }

        echo json_encode($health, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
