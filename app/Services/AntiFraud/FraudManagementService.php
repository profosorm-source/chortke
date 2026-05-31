<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Contracts\LoggerInterface;

class FraudManagementService
{
    private VelocityAndScoreModel $model;
    private IPQualityService $ipQualityService;
    private BrowserFingerprintService $fingerprintService;

    public function __construct(
        VelocityAndScoreModel $model,
        IPQualityService $ipQualityService,
        BrowserFingerprintService $fingerprintService
    ) {
                $this->model = $model;
        $this->ipQualityService = $ipQualityService;
        $this->fingerprintService = $fingerprintService;
    }

    public function getIpBlacklist(): array
    {
        return $this->model->getIpBlacklist();
    }

    public function blockIp(string $ip, string $reason, ?int $duration = null): void
    {
        $this->ipQualityService->blacklistIP($ip, $reason, $duration);
    }

    public function deleteIpBlacklistEntry(int $id): void
    {
        $this->model->deleteIpBlacklistEntry($id);
    }

    public function getDeviceBlacklist(): array
    {
        return $this->model->getDeviceBlacklist();
    }

    public function blockDevice(string $fingerprint, string $reason, ?int $duration = null): void
    {
        $this->fingerprintService->blacklistFingerprint($fingerprint, $reason, $duration);
    }

    public function deleteDeviceBlacklistEntry(int $id): void
    {
        $this->model->deleteDeviceBlacklistEntry($id);
    }

    public function getFraudLogs(int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $logs = $this->model->getFraudLogs($perPage, $offset);
        $total = $this->model->getFraudLogsCount();

        return [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => (int)ceil($total / $perPage),
            'total' => $total,
            'perPage' => $perPage,
        ];
    }
}
