<?php

declare(strict_types=1);

namespace App\Services\AntiFraud\Strategies;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\AntiFraud\VelocityCheckService;
use App\Services\AntiFraud\BehavioralBiometricsService;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Services\AntiFraud\VideoFingerprintService;
use App\Services\SocialTask\BehaviorAnalysisService;
use App\Services\AntiFraud\SeoFraudDetector;
use App\Services\FeatureFlagService;

final class TaskFraudStrategy implements FraudCheckStrategyInterface
{
    private \App\Contracts\LoggerInterface $logger;
    private FeatureFlagService $featureFlag;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        FeatureFlagService $featureFlag
    ) {        $this->logger = $logger;
        $this->featureFlag = $featureFlag;
}

    /**
     * Web automation, bot, and engagement checks for all task types.
     */
    public function check(int $userId, string $action, array $context): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\AntiFraud\CheckTaskFraudJob::class);
        return $job->handle($userId, $action, $context);
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
