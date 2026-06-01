<?php

declare(strict_types=1);

namespace App\Services\AntiFraud\Strategies;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\DeviceIntelligenceService;
use App\Services\AntiFraud\EmailPhoneIntelligenceService;
use App\Services\AntiFraud\GeolocationIntelligenceService;
use App\Services\FeatureFlagService;

final class IdentityFraudStrategy implements FraudCheckStrategyInterface
{
    private \App\Contracts\LoggerInterface $logger;
    private AccountTakeoverService $ato;
    private IPQualityService $ipQuality;
    private DeviceIntelligenceService $deviceIntel;
    private EmailPhoneIntelligenceService $emailPhoneIntel;
    private GeolocationIntelligenceService $geoIntel;
    private FeatureFlagService $featureFlag;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        AccountTakeoverService $ato,
        IPQualityService $ipQuality,
        DeviceIntelligenceService $deviceIntel,
        EmailPhoneIntelligenceService $emailPhoneIntel,
        GeolocationIntelligenceService $geoIntel,
        FeatureFlagService $featureFlag
    ) {        $this->logger = $logger;
        $this->ato = $ato;
        $this->ipQuality = $ipQuality;
        $this->deviceIntel = $deviceIntel;
        $this->emailPhoneIntel = $emailPhoneIntel;
        $this->geoIntel = $geoIntel;
        $this->featureFlag = $featureFlag;

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
            $this->logger->warning("anti_fraud.heavy_checks.bypassed", ['user_id' => $userId, 'action' => $action]);
        }

        return $results;
    }

    private function skipHeavyChecks(?int $userId): bool
    {
        try {
            return (bool) $this->featureFlag->isEnabled('anti_fraud.heavy_checks_disabled', $userId);
        } catch (\Throwable $e) {
            $this->logger->error("anti_fraud.ff_circuit_breaker.failed", ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
}
