<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Contracts\LoggerInterface;
use App\Models\IpAndDeviceModel;

class IPQualityService
{
    private IpAndDeviceModel $model;
    public function __construct(
        IpAndDeviceModel $model
    ) {        $this->model = $model;

            }

    public function check(string $ip): array
    {
        $isTor = $this->model->isTorNode($ip);
        $isVPN = $this->checkVPNRanges($ip);
        $isDatacenter = $this->checkDatacenterIP($ip);
        
        $score = 0;
        $reasons = [];
        
        if ($isTor) {
            $score = 100;
            $reasons[] = 'Tor Exit Node';
        } elseif ($isVPN) {
            $score = 70;
            $reasons[] = 'Commercial VPN';
        } elseif ($isDatacenter) {
            $score = 50;
            $reasons[] = 'Datacenter IP';
        }
        
        return [
            'status' => $score > 0 ? 'suspicious' : 'clean',
            'score' => $score,
            'risk_score' => $score,
            'fraud_score' => $score,
            'is_tor' => $isTor,
            'is_vpn' => $isVPN,
            'is_proxy' => $isTor || $isVPN,
            'is_datacenter' => $isDatacenter,
            'is_suspicious' => $score >= 50,
            'reasons' => $reasons,
        ];
    }

    private function checkVPNRanges(string $ip): bool
    {
        $ranges = $this->model->getSuspiciousIpRanges();
        foreach ($ranges as $range) {
            if (isset($range->ip_range) && $this->ipInRange($ip, $range->ip_range)) {
                return true;
            }
        }
        return false;
    }

    private function checkDatacenterIP(string $ip): bool
    {
        $ranges = config('anti_fraud.datacenter_ip_ranges', []);
        foreach ((array)$ranges as $range) {
            if ($this->ipInRange($ip, (string)$range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = -1 << (32 - (int)$mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
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
