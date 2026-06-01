<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\Health\HealthCheckService;

class HealthCheckController extends BaseController
{
    private HealthCheckService $healthService;

    public function __construct(HealthCheckService $healthService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->healthService = $healthService;
    }

    /**
     * Liveness Probe: Used by orchestrators (k8s/Docker) to know if container is running.
     * GET /api/health/live
     */
    public function live(): void
    {
        $this->validateAccess();

        $result = $this->healthService->checkLiveness();
        
        $statusCode = $result['status'] === 'error' ? 503 : 200;
        
        $this->jsonResponse($result, $statusCode);
    }

    /**
     * Readiness Probe: Used by load balancers to know if node is ready for traffic.
     * GET /api/health/ready
     */
    public function ready(): void
    {
        $this->validateAccess();

        $result = $this->healthService->checkReadiness();
        
        // A degraded status means we have issues, but we might still accept traffic (e.g. Queue is slow, but API works).
        // Error means we should be pulled from Load Balancer.
        $statusCode = $result['status'] === 'error' ? 503 : 200;
        
        $this->jsonResponse($result, $statusCode);
    }

    private function validateAccess(): void
    {
        $allowedIps = config('health.allowed_ips', ['127.0.0.1', '::1']);
        $token = config('health.check_token', '');
        
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $requestToken = $this->request->get('token', $this->request->header('X-Health-Token', ''));
        
        $isIpAllowed = empty($allowedIps) || in_array($clientIp, $allowedIps, true);
        $isTokenValid = !empty($token) && hash_equals($token, $requestToken);
        
        if (!$isIpAllowed && !$isTokenValid) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Unauthorized Access'], 403);
            exit;
        }
    }
}
