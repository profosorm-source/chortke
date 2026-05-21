<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Services\BaseService;
use App\Contracts\LoggerInterface;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Services\SocialTask\BehaviorAnalysisService;

final class FraudGuardService extends BaseService
{
    public function __construct(
        private RiskDecisionService $riskDecision,
        private FraudDetectionService $fraudDetection,
        private AccountTakeoverService $ato,
        private IPQualityService $ipQuality,
        private DeviceIntelligenceService $deviceIntel,
        private EmailPhoneIntelligenceService $emailPhoneIntel,
        private GeolocationIntelligenceService $geoIntel,
        private VelocityCheckService $velocity,
        private RateLimitingService $rateLimiting,
        private SessionAnomalyService $sessionAnomaly,
        private BehavioralBiometricsService $biometrics,
        private SilentAntiFraudService $silentAntiFraud,
        private VideoFingerprintService $videoFingerprint,
        private BehaviorAnalysisService $behaviorAnalysis,
        private SeoFraudDetector $seoDetector,
        private \App\Services\FeatureFlagService $featureFlag,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * Unified evaluation entrypoint for processing user-action risks.
     * Decoupled constructor injection allows precise unit testing and avoids container anti-patterns.
     *
     * @param int $userId The user initiating the action.
     * @param string $action Unique tag identifying the flow (e.g., 'auth.login', 'payment.create').
     * @param array $context Associated contextual facts (IP, user-agent, payload amounts, etc.).
     * @return array Unified evaluation format ['allowed' => bool, 'action' => 'allow|block|limit', 'reason' => string]
     */
    public function checkAction(int $userId, string $action, array $context = []): array
    {
        try {
            $this->logInfo("anti_fraud.check_initiated", [
                'user_id' => $userId,
                'action'  => $action,
            ]);

            // 📦 Refactored orchestration: Distributes action handling to modular routers.
            $results = $this->runActionSubChecks($action, $userId, $context);
            
            // Synthesize overall automatic fraud score engine
            $score = $this->fraudDetection->calculateFraudScore($userId);

            // Execute unified policy decision map via RiskDecisionService
            $decision = $this->riskDecision->decide($userId, array_merge($context, [
                'action'          => $action,
                'fraud_score'     => $score,
                'partial_results' => $results
            ]));

            // Final decision logic compilator
            return $this->compileFinalDecision($userId, $action, $score, $results, $decision);

        } catch (\Throwable $e) {
            return $this->handleSystemFailure($userId, $action, $e);
        }
    }

    /**
     * Router mapping for specific security validation pipelines.
     */
    private function runActionSubChecks(string $action, int $userId, array $context): array
    {
        switch ($action) {
            case 'auth.login':
            case 'auth.register':
                return $this->runAuthChecks($userId, $context);
                
            case 'payment.create':
                return $this->runPaymentChecks($userId, $context);

            case 'withdrawal.create':
                return $this->runWithdrawalChecks($userId, $context);

            case 'wallet.transfer':
                return $this->runWalletChecks($userId, $context);

            case 'crypto.deposit':
                return $this->runCryptoChecks($userId, $context);

            case 'task.custom':
                return $this->runCustomTaskChecks($userId, $context);

            case 'task.social':
                return $this->runSocialTaskChecks($userId, $context);

            case 'task.seo':
                return $this->runSeoChecks($userId, $context);

            default:
                $this->logWarning("anti_fraud.unknown_action_called", ['action' => $action, 'user_id' => $userId]);
                return [];
        }
    }

    /**
     * Compiler aggregating raw partial decisions into deterministic final system responses.
     */
    private function compileFinalDecision(int $userId, string $action, int $score, array $results, array $decision): array
    {
        $isAllowed = !in_array($decision['decision'], ['block', 'suspend'], true);
        $finalReason = $decision['reason'] ?? 'OK';
        $finalAction = $decision['decision'];

        // Deterministic sub-check override (e.g. if explicit velocity control failed)
        if (isset($results['velocity']['allowed']) && !$results['velocity']['allowed']) {
            $isAllowed = false;
            $finalAction = 'limit';
            $finalReason = $results['velocity']['reason'] ?? 'Velocity limits exceeded';
        }
        
        if (isset($results['rate_limit']['allowed']) && !$results['rate_limit']['allowed']) {
            $isAllowed = false;
            $finalAction = 'block';
            $finalReason = 'Rate limits exceeded';
        }

        $this->logInfo("anti_fraud.check_completed", [
            'user_id'  => $userId,
            'action'   => $action,
            'allowed'  => $isAllowed,
            'decision' => $finalAction,
            'reason'   => $finalReason
        ]);

        return [
            'allowed' => $isAllowed,
            'action'  => $finalAction,
            'score'   => $score,
            'reason'  => $finalReason,
            'details' => array_merge($results, ['decision_payload' => $decision])
        ];
    }

    /**
     * Standardized fail-safe recovery strategy.
     */
    private function handleSystemFailure(int $userId, string $action, \Throwable $e): array
    {
        $sensitiveActions = ['payment.create', 'withdrawal.create', 'wallet.transfer', 'auth.login'];
        $isSensitive = in_array($action, $sensitiveActions, true);

        $this->logError("anti_fraud.system_failure", [
            'user_id' => $userId,
            'action'  => $action,
            'error'   => $e->getMessage(),
            'fail_mode' => $isSensitive ? 'fail-closed' : 'fail-open'
        ]);

        return [
            'allowed' => !$isSensitive, // deny sensitive, allow others
            'action'  => $isSensitive ? 'deny' : 'allow',
            'reason'  => $isSensitive ? 'system_critical_fail_closed' : 'system_critical_fallback',
            'score'   => 0,
            'details' => ['error' => $e->getMessage()]
        ];
    }

    /**
     * Multi-layered authentication identity checks.
     */
    private function runAuthChecks(int $userId, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->getClientIp();
        $ua = $context['user_agent'] ?? $this->getUserAgent();
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
            $this->logWarning("anti_fraud.heavy_checks.bypassed", ['user_id' => $userId, 'action' => 'runAuthChecks']);
        }

        return $results;
    }

    /**
     * Payment transaction velocity and safety gates.
     */
    private function runPaymentChecks(int $userId, array $context): array
    {
        $results = [];

        // A. Velocity Checks (Payment limits, patterns)
        $results['velocity'] = $this->velocity->check($userId, 'deposit', $context);

        // B. Dynamic Rate Limiting
        $results['rate_limit'] = $this->rateLimiting->checkTokenBucket("payment:{$userId}", 'payment_attempt');

        return $results;
    }

    /**
     * Withdrawal verification layer.
     */
    private function runWithdrawalChecks(int $userId, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->getClientIp();
        $ua = $context['user_agent'] ?? $this->getUserAgent();

        // A. Velocity Check
        $results['velocity'] = $this->velocity->check($userId, 'withdrawal', $context);

        // B. Geolocation Anomaly Checks
        $results['geolocation'] = $this->geoIntel->analyze($userId, $ip);

        // C. Check recent ATO flags
        $results['takeover'] = $this->ato->detect($userId, $ip, $ua);

        return $results;
    }

    /**
     * Wallet transfers (peer-to-peer).
     */
    private function runWalletChecks(int $userId, array $context): array
    {
        $results = [];

        // A. Velocity
        $results['velocity'] = $this->velocity->check($userId, 'transfer', $context);

        return $results;
    }

    /**
     * Crypto deposit detection gates.
     */
    private function runCryptoChecks(int $userId, array $context): array
    {
        $results = [];

        $results['velocity'] = $this->velocity->check($userId, 'deposit', $context);

        if (!$this->skipHeavyChecks($userId)) {
            if (!empty($context['device_info'])) {
                $results['device'] = $this->deviceIntel->comprehensiveAnalysis($context['device_info']);
            }
        }

        return $results;
    }

    /**
     * Web automation, anomaly detection and bot checking for Custom Tasks.
     */
    private function runCustomTaskChecks(int $userId, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->getClientIp();
        $sessionId = $context['session_id'] ?? session_id();

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

        return $results;
    }

    /**
     * Camera verification, visual and behavior tracking for Social Tasks.
     */
    private function runSocialTaskChecks(int $userId, array $context): array
    {
        $results = [];

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

        return $results;
    }

    /**
     * Bot clicking / browser automation checks for SEO flows.
     */
    private function runSeoChecks(int $userId, array $context): array
    {
        $results = [];
        $sessionId = $context['session_id'] ?? session_id();

        // A. Central Seo Fraud Detection Engine
        $adId = (int)($context['ad_id'] ?? 0);
        $engagementData = $context['engagement_data'] ?? [];
        $results['seo_fraud'] = $this->seoDetector->detect($userId, $adId, $engagementData);

        // B. Session Tracking
        if ($sessionId) {
            $results['session'] = $this->sessionAnomaly->analyze($userId, $sessionId);
        }

        return $results;
    }

    /**
     * Centralized circuit breaker strategy evaluating Feature Flags to bypass CPU/I/O heavy calculations.
     */
    private function skipHeavyChecks(?int $userId): bool
    {
        try {
            // Checks database/cache configuration dynamically via active feature flag
            return (bool) $this->featureFlag->isEnabled('anti_fraud.heavy_checks_disabled', $userId);
        } catch (\Throwable $e) {
            $this->logError("anti_fraud.ff_circuit_breaker.failed", ['error' => $e->getMessage()]);
            return false; // Fallback: perform checks if feature flags fail
        }
    }
}
