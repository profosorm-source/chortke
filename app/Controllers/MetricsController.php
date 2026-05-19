<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * MetricsController - Exposes Prometheus metrics
 */
class MetricsController extends \App\Controllers\BaseController
{
    private Database $db;

    public function __construct(LoggerInterface $logger, Database $db)
    {
        parent::__construct($logger);
        $this->db = $db;
    }

    public function metrics(): void
    {
        // 1. IP Whitelisting / Token Authorization
        $allowedIps = config('health.allowed_ips', ['127.0.0.1', '::1']);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $token = $_GET['token'] ?? '';
        $expectedToken = config('health.check_token');

        if (!in_array($clientIp, $allowedIps, true) && (!empty($expectedToken) && $token !== $expectedToken)) {
            http_response_code(403);
            exit('Forbidden');
        }

        header('Content-Type: text/plain; charset=utf-8');

        // Total HTTP Requests (Assuming we can get it from activity logs or it's a proxy for it)
        $requestsCount = $this->db->query("SELECT COUNT(*) FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
        
        echo "# HELP app_requests_total Total HTTP requests in last 5 min\n";
        echo "# TYPE app_requests_total counter\n";
        echo "app_requests_total " . (int)$requestsCount . "\n";

        // Application Errors
        $errorCount = $this->db->query("SELECT COUNT(*) FROM system_logs WHERE level = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
        $criticalCount = $this->db->query("SELECT COUNT(*) FROM system_logs WHERE level = 'critical' AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();

        echo "# HELP app_errors_total Application errors\n";
        echo "# TYPE app_errors_total counter\n";
        echo "app_errors_total{level=\"error\"} " . (int)$errorCount . "\n";
        echo "app_errors_total{level=\"critical\"} " . (int)$criticalCount . "\n";

        // Active Users
        $activeUsers = $this->db->query("SELECT COUNT(*) FROM users WHERE status = 1")->fetchColumn();
        
        echo "# HELP app_active_users Current active users\n";
        echo "# TYPE app_active_users gauge\n";
        echo "app_active_users " . (int)$activeUsers . "\n";

        // DB Status
        echo "# HELP app_db_up Database availability\n";
        echo "# TYPE app_db_up gauge\n";
        try {
            $this->db->query("SELECT 1");
            echo "app_db_up 1\n";
        } catch (\Exception $e) {
            echo "app_db_up 0\n";
        }
    }
}
