<?php

declare(strict_types=1);

namespace App\Services\AntiFraud\Strategies;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Services\BaseService;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\VelocityCheckService;
use App\Services\AntiFraud\RateLimitingService;
use App\Services\AntiFraud\GeolocationIntelligenceService;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\DeviceIntelligenceService;
use App\Services\FeatureFlagService;

final class TransactionFraudStrategy implements FraudCheckStrategyInterface
{
    public function __construct(
        private \App\Contracts\LoggerInterface $logger,
        private VelocityCheckService $velocity,
        private RateLimitingService $rateLimiting,
        private GeolocationIntelligenceService $geoIntel,
        private AccountTakeoverService $ato,
        private DeviceIntelligenceService $deviceIntel,
        private FeatureFlagService $featureFlag
    ) {
            }

    /**
     * Financial transactions velocity and safety gates.
     */
    public function check(int $userId, string $action, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->clientIp();
        $ua = $context['user_agent'] ?? $this->userAgent();

        switch ($action) {
            case 'payment.create':
                // A. Velocity Checks (Payment limits, patterns)
                $results['velocity'] = $this->velocity->check($userId, 'deposit', $context);
                // B. Dynamic Rate Limiting
                $results['rate_limit'] = $this->rateLimiting->checkTokenBucket("payment:{$userId}", 'payment_attempt');
                break;

            case 'withdrawal.create':
                // A. Velocity Check
                $results['velocity'] = $this->velocity->check($userId, 'withdrawal', $context);
                // B. Geolocation Anomaly Checks
                $results['geolocation'] = $this->geoIntel->analyze($userId, $ip);
                // C. Check recent ATO flags
                $results['takeover'] = $this->ato->detect($userId, $ip, $ua);
                break;

            case 'wallet.transfer':
                // A. Velocity
                $results['velocity'] = $this->velocity->check($userId, 'transfer', $context);
                break;

            case 'crypto.deposit':
                // A. Velocity check
                $results['velocity'] = $this->velocity->check($userId, 'deposit', $context);
                // B. Heavy device analysis
                if (!$this->skipHeavyChecks($userId)) {
                    if (!empty($context['device_info'])) {
                        $results['device'] = $this->deviceIntel->comprehensiveAnalysis($context['device_info']);
                    }
                }
                break;
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
}
