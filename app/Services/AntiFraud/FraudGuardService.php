<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Contracts\LoggerInterface;

final class FraudGuardService
{
    private \App\Contracts\LoggerInterface $logger;
    private RiskDecisionService $riskDecision;
    private FraudDetectionService $fraudDetection;
    private FraudStrategyResolver $strategyResolver;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        RiskDecisionService $riskDecision,
        FraudDetectionService $fraudDetection,
        FraudStrategyResolver $strategyResolver
    ) {        $this->logger = $logger;
        $this->riskDecision = $riskDecision;
        $this->fraudDetection = $fraudDetection;
        $this->strategyResolver = $strategyResolver;

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
            $this->logger->info("anti_fraud.check_initiated", [
                'user_id' => $userId,
                'action'  => $action,
            ]);

            // 📦 Refactored orchestration: Resolves the specialized strategy lazily to avoid booting unused dependencies
            $strategy = $this->strategyResolver->resolve($action);
            
            if (!$strategy) {
                $this->logger->warning("anti_fraud.unknown_action_called", ['action' => $action, 'user_id' => $userId]);
                $results = [];
            } else {
                $results = $strategy->check($userId, $action, $context);
            }
            
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

        $this->logger->info("anti_fraud.check_completed", [
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

        $this->logger->error("anti_fraud.system_failure", [
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
