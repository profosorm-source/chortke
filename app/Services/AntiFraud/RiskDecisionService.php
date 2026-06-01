<?php

namespace App\Services\AntiFraud;

use App\Services\ScoreService;
use Core\Database;

use App\Contracts\LoggerInterface;
class RiskDecisionService
{

    private RiskPolicyService $policyService;
    private ScoreService $scoreService;

    private \Core\Database $db;
    public function __construct(
        \Core\Database $db,
        RiskPolicyService $policyService,
        ScoreService $scoreService
    ) {        $this->db = $db;

        
        $this->policyService = $policyService;
        $this->scoreService = $scoreService;
    }

    /**
     * تصمیم نهایی غیرجبرانی:
     * allow | challenge | limit | block
     */
    public function decide(int $userId, array $context = []): array
    {
        $action = (string)($context['action'] ?? 'general');

        $fraudScore = $this->scoreService->getScore('user', $userId, \App\Enums\ScoreDomain::Fraud->value);
        $kycStatus = $this->getKycStatus($userId);

        $blockThreshold = $this->policyService->getInt('fraud', 'block_threshold', 80);
        $challengeThreshold = $this->policyService->getInt('fraud', 'challenge_threshold', 60);
        $limitThreshold = $this->policyService->getInt('fraud', 'limit_threshold', 40);

        $kycRejectedVetoFinancial = $this->policyService->getBool('kyc', 'rejected_veto_financial', true);

        // Veto 1: KYC rejected برای عملیات مالی
        if ($kycRejectedVetoFinancial && $kycStatus === 'rejected' && $this->isFinancialAction($action)) {
            return $this->decision('block', 'kyc_rejected_veto', $fraudScore, $kycStatus, $action);
        }

        // Veto 2: Fraud خیلی بالا
        if ($fraudScore >= $blockThreshold) {
            return $this->decision('block', 'fraud_block_threshold', $fraudScore, $kycStatus, $action);
        }

        if ($fraudScore >= $challengeThreshold) {
            return $this->decision('challenge', 'fraud_challenge_threshold', $fraudScore, $kycStatus, $action);
        }

        if ($fraudScore >= $limitThreshold) {
            return $this->decision('limit', 'fraud_limit_threshold', $fraudScore, $kycStatus, $action);
        }

        return $this->decision('allow', 'ok', $fraudScore, $kycStatus, $action);
    }

    private function decision(string $result, string $reason, float $fraudScore, string $kycStatus, string $action): array
    {
        return [
    'result' => $result,
    'decision' => $result, // backward compatibility
    'reason' => $reason,
    'fraud_score' => $fraudScore,
    'kyc_status' => $kycStatus,
    'action' => $action,
];
    }

    private function isFinancialAction(string $action): bool
    {
        return in_array($action, [
            'withdraw',
            'manual_deposit',
            'crypto_deposit',
            'wallet_transfer',
            'payment',
            // Actionهای جدید سیستم
            'payment.create',
            'withdrawal.create',
            'wallet.transfer',
            'crypto.deposit',
        ], true);
    }

    private function getKycStatus(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT kyc_status FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $status = $stmt->fetchColumn();

        return $status ? (string)$status : 'unverified';
    }
}
