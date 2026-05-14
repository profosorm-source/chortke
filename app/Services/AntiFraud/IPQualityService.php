<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Contracts\LoggerInterface;
use App\Models\IpAndDeviceModel;

class IPQualityService extends \App\Services\BaseService
{
    public function __construct(
        private IpAndDeviceModel $model,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function check(string $ip): array
    {
        // TODO: Implement actual IP quality check
        return [
            'status' => 'unknown',
            'is_unknown' => true,
            'score' => 0,
            'risk_score' => 0,
            'fraud_score' => 0,
            'reasons' => [],
            'is_vpn' => false,
            'is_proxy' => false,
            'is_tor' => false,
            'is_datacenter' => false,
            'is_suspicious' => false,
        ];
    }

    public function checkIp(string $ip): array
    {
        return $this->check($ip);
    }

    public function blacklistIP(string $ip, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        $this->model->blacklistIp($ip, $reason, $expiresAt);
    }
}
