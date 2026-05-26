<?php

declare(strict_types=1);

namespace App\Services\AntiFraud\Strategies;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Services\BaseService;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\DeviceIntelligenceService;
use App\Services\AntiFraud\EmailPhoneIntelligenceService;
use App\Services\AntiFraud\GeolocationIntelligenceService;
use App\Services\FeatureFlagService;

final class IdentityFraudStrategy extends BaseService implements FraudCheckStrategyInterface
{
    public function __construct(
        private AccountTakeoverService $ato,
        private IPQualityService $ipQuality,
        private DeviceIntelligenceService $deviceIntel,
        private EmailPhoneIntelligenceService $emailPhoneIntel,
        private GeolocationIntelligenceService $geoIntel,
        private FeatureFlagService $featureFlag,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Run authentication identity checks.
     */
    public function check(int $userId, string $action, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->clientIp();
        $ua = $context['user_agent'] ?? $this->userAgent();
        $fingerprint = $context['fingerprint'] ?? null;

        // A. ATO (Account Takeover Detection)
        $results['takeover'] = $this->ato->detect($userId, $ip, $ua, $fingerprint);

        // B. IP Quality Verification
        $results['ip_quality'] = $this->ipQuality->check($ip);

        // Bypassing heavy external/internal profiling under high-load Feature Flag to prevent latency spikes
        if (!$this->skipHeavyChecks($userId)) {
            // C. Device Emulation/Environment Intelligence
            if (!empty($context['device_info'])) {
                $results['device'] = $this->deviceIntel->comprehensiveAnalysis($context['device_info']);
            }

            // D. Email/Phone intelligence verification
            if (!empty($context['email'])) {
                $results['email'] = $this->emailPhoneIntel->analyzeEmail($context['email']);
            }
            if (!empty($context['phone'])) {
                $results['phone'] = $this->emailPhoneIntel->analyzePhone($context['phone']);
            }

            // E. Geolocation & Velocity Anomaly Checks
            $results['geolocation'] = $this->geoIntel->analyze($userId, $ip, $context);
        } else {
            $this->logWarning("anti_fraud.heavy_checks.bypassed", ['user_id' => $userId, 'action' => $action]);
        }

        return $results;
    }

    private function skipHeavyChecks(?int $userId): bool
    {
        try {
            return (bool) $this->featureFlag->isEnabled('anti_fraud.heavy_checks_disabled', $userId);
        } catch (\Throwable $e) {
            $this->logError("anti_fraud.ff_circuit_breaker.failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
