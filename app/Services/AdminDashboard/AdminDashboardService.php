<?php

namespace App\Services\AdminDashboard;

use App\Models\User;
use App\Contracts\LoggerInterface;
/**
 * AdminDashboardService (Orchestrator)
 * 
 * پس از تجزیه، این کلاس درخواست‌ها را به سرویس‌های تخصصی‌تر هدایت می‌کند
 * تا پایداری و اصل SRP رعایت شود و تغییری در کنترلرها نیاز نباشد.
 */
class AdminDashboardService extends \App\Services\BaseService
{
    private User $userModel;
    private DashboardQueryService $queryService;
    private SystemMonitoringService $monitoringService;

    public function __construct(
        User $userModel,
        LoggerInterface $logger,
        DashboardQueryService $queryService,
        SystemMonitoringService $monitoringService
    ) {
        parent::__construct($logger);
        $this->userModel = $userModel;
        $this->queryService = $queryService;
        $this->monitoringService = $monitoringService;
    }

    public function getDashboardData(int $userId): array
    {
        return $this->queryService->getDashboardData($userId);
    }

    public function getAdminAccessLog(int $limit = 10): array
    {
        return $this->queryService->getAdminAccessLog($limit);
    }

    public function getRecentActivity(string $type = 'all', int $limit = 20, int $page = 1): array
    {
        return $this->queryService->getRecentActivity($type, $limit, $page);
    }

    public function getSystemStatus(): array
    {
        return $this->monitoringService->getSystemStatus();
    }

    public function attemptLogin(string $email, string $password): ?array
    {
        $user = $this->userModel->findAdminByEmail($email);

        if (!$user || !password_verify($password, $user->password)) {
            return null;
        }

        $this->userModel->update((int)$user->id, ['last_login' => date('Y-m-d H:i:s')]);

        return ['id' => (int)$user->id, 'role' => $user->role];
    }
}

