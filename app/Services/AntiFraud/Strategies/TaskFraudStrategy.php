<?php

declare(strict_types=1);

namespace App\Services\AntiFraud\Strategies;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Services\BaseService;
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

final class TaskFraudStrategy extends BaseService implements FraudCheckStrategyInterface
{
    public function __construct(
        private IPQualityService $ipQuality,
        private SessionAnomalyService $sessionAnomaly,
        private VelocityCheckService $velocity,
        private BehavioralBiometricsService $biometrics,
        private SilentAntiFraudService $silentAntiFraud,
        private VideoFingerprintService $videoFingerprint,
        private BehaviorAnalysisService $behaviorAnalysis,
        private SeoFraudDetector $seoDetector,
        private FeatureFlagService $featureFlag,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Web automation, bot, and engagement checks for all task types.
     */
    public function check(int $userId, string $action, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->clientIp();
        $sessionId = $context['session_id'] ?? session_id();

        switch ($action) {
            case 'task.custom':
                // A. IP Quality Check
                $results['ip_quality'] = $this->ipQuality->check($ip);
                // B. Session Anomalies
                if ($sessionId) {
                    $results['session'] = $this->sessionAnomaly->analyze($userId, $sessionId);
                }
                // C. Velocity Check
                $results['velocity'] = $this->velocity->check($userId, 'task_execution', $context);
                // D. Behavioral Biometrics analysis (Heavy JS parse bypassed on high load)
                if (!$this->skipHeavyChecks($userId)) {
                    if (!empty($context['biometric_data'])) {
                        $results['biometrics'] = $this->biometrics->analyzePatterns($userId, $context['biometric_data']);
                    }
                }
                break;

            case 'task.social':
                // A. Automated Social Tasks anti-fraud
                $results['silent_fraud'] = $this->silentAntiFraud->evaluateExecution($userId, $context['task_id'] ?? 0, $context);
                // B. Video Fingerprint / Duplicate detection (Skipped if global circuit breaker is ON)
                if (!$this->skipHeavyChecks($userId)) {
                    if (!empty($context['video_hash'])) {
                        $results['video'] = $this->videoFingerprint->checkDuplicate($userId, $context['video_hash']);
                    }
                }
                // C. Social interaction behavior analysis
                $results['behavior'] = $this->behaviorAnalysis->analyzeTaskActivity($userId, $context);
                break;

            case 'task.seo':
                // A. Central Seo Fraud Detection Engine
                $adId = (int)($context['ad_id'] ?? 0);
                $engagementData = $context['engagement_data'] ?? [];
                $results['seo_fraud'] = $this->seoDetector->detect($userId, $adId, $engagementData);
                // B. Session Tracking
                if ($sessionId) {
                    $results['session'] = $this->sessionAnomaly->analyze($userId, $sessionId);
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
            $this->logError("anti_fraud.ff_circuit_breaker.failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
}
