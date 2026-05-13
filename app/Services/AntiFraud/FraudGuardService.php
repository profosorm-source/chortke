<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Services\BaseService;
use App\Contracts\LoggerInterface;
use Core\Container;

final class FraudGuardService extends BaseService
{
    private Container $container;

    public function __construct(
        private RiskDecisionService $riskDecision,
        private FraudDetectionService $fraudDetection,
        Container $container,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->container = $container;
    }

    /**
     * Unified evaluation entrypoint for processing user-action risks.
     * Resolves auxiliary engines dynamically via DI Container to avoid initialization bloat.
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

            $results = [];
            $container = $this->container;

            // 1. Dynamically execute action-specific sub-checks
            switch ($action) {
                case 'auth.login':
                case 'auth.register':
                    $results = $this->runAuthChecks($container, $userId, $context);
                    break;
                    
                case 'payment.create':
                    $results = $this->runPaymentChecks($container, $userId, $context);
                    break;

                case 'withdrawal.create':
                    $results = $this->runWithdrawalChecks($container, $userId, $context);
                    break;

                case 'wallet.transfer':
                    $results = $this->runWalletChecks($container, $userId, $context);
                    break;

                case 'crypto.deposit':
                    $results = $this->runCryptoChecks($container, $userId, $context);
                    break;

                case 'task.custom':
                    $results = $this->runCustomTaskChecks($container, $userId, $context);
                    break;

                case 'task.social':
                    $results = $this->runSocialTaskChecks($container, $userId, $context);
                    break;

                case 'task.seo':
                    $results = $this->runSeoChecks($container, $userId, $context);
                    break;

                default:
                    $this->logWarning("anti_fraud.unknown_action_called", ['action' => $action, 'user_id' => $userId]);
            }

            // 2. Synthesize overall automatic fraud score engine
            $score = $this->fraudDetection->calculateFraudScore($userId);

            // 3. Execute unified policy decision map via RiskDecisionService
            $decision = $this->riskDecision->decide($userId, array_merge($context, [
                'action'          => $action,
                'fraud_score'     => $score,
                'partial_results' => $results
            ]));

            // 4. Compile final allowance logic
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

        } catch (\Throwable $e) {
            // Fail-safe logic: deny sensitive actions on system failure, allow others
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
    }

    /**
     * Multi-layered authentication identity checks.
     */
    private function runAuthChecks(Container $container, int $userId, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->getClientIp();
        $ua = $context['user_agent'] ?? $this->getUserAgent();
        $fingerprint = $context['fingerprint'] ?? null;

        // A. ATO (Account Takeover Detection)
        if ($container->has(AccountTakeoverService::class)) {
            $results['takeover'] = $container->make(AccountTakeoverService::class)->detect($userId, $ip, $ua, $fingerprint);
        }

        // B. IP Quality Verification
        if ($container->has(IPQualityService::class)) {
            $results['ip_quality'] = $container->make(IPQualityService::class)->check($ip);
        }

        // C. Device Emulation/Environment Intelligence
        if ($container->has(DeviceIntelligenceService::class) && !empty($context['device_info'])) {
            $results['device'] = $container->make(DeviceIntelligenceService::class)->comprehensiveAnalysis($context['device_info']);
        }

        // D. Email/Phone intelligence verification
        if ($container->has(EmailPhoneIntelligenceService::class)) {
            $service = $container->make(EmailPhoneIntelligenceService::class);
            if (!empty($context['email'])) {
                $results['email'] = $service->analyzeEmail($context['email']);
            }
            if (!empty($context['phone'])) {
                $results['phone'] = $service->analyzePhone($context['phone']);
            }
        }

        // E. Geolocation & Velocity Anomaly Checks
        if ($container->has(GeolocationIntelligenceService::class)) {
            $results['geolocation'] = $container->make(GeolocationIntelligenceService::class)->analyze($userId, $ip, $context);
        }

        return $results;
    }

    /**
     * Payment transaction velocity and safety gates.
     */
    private function runPaymentChecks(Container $container, int $userId, array $context): array
    {
        $results = [];

        // A. Velocity Checks (Payment limits, patterns)
        if ($container->has(VelocityCheckService::class)) {
            $results['velocity'] = $container->make(VelocityCheckService::class)->check($userId, 'deposit', $context);
        }

        // B. Dynamic Rate Limiting
        if ($container->has(RateLimitingService::class)) {
            $results['rate_limit'] = $container->make(RateLimitingService::class)->checkTokenBucket("payment:{$userId}", 'payment_attempt');
        }

        return $results;
    }

    /**
     * Withdrawal verification layer.
     */
    private function runWithdrawalChecks(Container $container, int $userId, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->getClientIp();
        $ua = $context['user_agent'] ?? $this->getUserAgent();

        // A. Velocity Check
        if ($container->has(VelocityCheckService::class)) {
            $results['velocity'] = $container->make(VelocityCheckService::class)->check($userId, 'withdrawal', $context);
        }

        // B. Geolocation Anomaly Checks
        if ($container->has(GeolocationIntelligenceService::class)) {
            $results['geolocation'] = $container->make(GeolocationIntelligenceService::class)->analyze($userId, $ip);
        }

        // C. Check recent ATO flags
        if ($container->has(AccountTakeoverService::class)) {
            $results['takeover'] = $container->make(AccountTakeoverService::class)->detect($userId, $ip, $ua);
        }

        return $results;
    }

    /**
     * Wallet transfers (peer-to-peer).
     */
    private function runWalletChecks(Container $container, int $userId, array $context): array
    {
        $results = [];

        // A. Velocity
        if ($container->has(VelocityCheckService::class)) {
            $results['velocity'] = $container->make(VelocityCheckService::class)->check($userId, 'transfer', $context);
        }

        // B. Shared financial intelligence mapping
        if ($container->has(\App\Services\Shared\FinancialService::class)) {
            // Placeholder for evaluating complex financial logic if applicable
        }

        return $results;
    }

    /**
     * Crypto deposit detection gates.
     */
    private function runCryptoChecks(Container $container, int $userId, array $context): array
    {
        $results = [];

        if ($container->has(VelocityCheckService::class)) {
            $results['velocity'] = $container->make(VelocityCheckService::class)->check($userId, 'deposit', $context);
        }

        if ($container->has(DeviceIntelligenceService::class) && !empty($context['device_info'])) {
            $results['device'] = $container->make(DeviceIntelligenceService::class)->comprehensiveAnalysis($context['device_info']);
        }

        return $results;
    }

    /**
     * Web automation, anomaly detection and bot checking for Custom Tasks.
     */
    private function runCustomTaskChecks(Container $container, int $userId, array $context): array
    {
        $results = [];
        $ip = $context['ip'] ?? $this->getClientIp();
        $sessionId = $context['session_id'] ?? session_id();

        // A. IP Quality Check
        if ($container->has(IPQualityService::class)) {
            $results['ip_quality'] = $container->make(IPQualityService::class)->check($ip);
        }

        // B. Session Anomalies
        if ($container->has(SessionAnomalyService::class) && $sessionId) {
            $results['session'] = $container->make(SessionAnomalyService::class)->analyze($userId, $sessionId);
        }

        // C. Velocity Check
        if ($container->has(VelocityCheckService::class)) {
            $results['velocity'] = $container->make(VelocityCheckService::class)->check($userId, 'task_execution', $context);
        }

        // D. Behavioral Biometrics analysis
        if ($container->has(BehavioralBiometricsService::class) && !empty($context['biometric_data'])) {
            $results['biometrics'] = $container->make(BehavioralBiometricsService::class)->analyzePatterns($userId, $context['biometric_data']);
        }

        return $results;
    }

    /**
     * Camera verification, visual and behavior tracking for Social Tasks.
     */
    private function runSocialTaskChecks(Container $container, int $userId, array $context): array
    {
        $results = [];

        // A. Automated Social Tasks anti-fraud
        if ($container->has(\App\Services\SocialTask\SilentAntiFraudService::class)) {
            $results['silent_fraud'] = $container->make(\App\Services\SocialTask\SilentAntiFraudService::class)->evaluateExecution($userId, $context['task_id'] ?? 0, $context);
        }

        // B. Video Fingerprint / Duplicate detection
        if ($container->has(VideoFingerprintService::class) && !empty($context['video_hash'])) {
            $results['video'] = $container->make(VideoFingerprintService::class)->checkDuplicate($userId, $context['video_hash']);
        }

        // C. Social interaction behavior analysis
        if ($container->has(\App\Services\SocialTask\BehaviorAnalysisService::class)) {
            $results['behavior'] = $container->make(\App\Services\SocialTask\BehaviorAnalysisService::class)->analyzeTaskActivity($userId, $context);
        }

        return $results;
    }

    /**
     * Bot clicking / browser automation checks for SEO flows.
     */
    private function runSeoChecks(Container $container, int $userId, array $context): array
    {
        $results = [];
        $sessionId = $context['session_id'] ?? session_id();

        // A. Central Seo Fraud Detection Engine
        if ($container->has(SeoFraudDetector::class)) {
            $results['seo_fraud'] = $container->make(SeoFraudDetector::class)->evaluateClick($userId, $context);
        }

        // B. Session Tracking
        if ($container->has(SessionAnomalyService::class) && $sessionId) {
            $results['session'] = $container->make(SessionAnomalyService::class)->analyze($userId, $sessionId);
        }

        return $results;
    }
}
