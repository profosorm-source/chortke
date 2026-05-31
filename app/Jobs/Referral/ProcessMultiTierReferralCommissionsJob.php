<?php

declare(strict_types=1);

namespace App\Jobs\Referral;

class ProcessMultiTierReferralCommissionsJob
{
    public function __construct(
        private \App\Models\User $userModel,
        private \App\Services\Settings\AppSettings $appSettings
    ) {}

    public function handle(int $userId, string $amount, string $currency): array
    {
        $processed = [];
        $referrer = $this->userModel->findById($userId);

        if ($referrer && $referrer->referred_by) {
            $referrerId = (int)$referrer->referred_by;
            if (!$this->detectCircularReferral($userId, $referrerId)) {
                $processed[1] = $this->processCommission($referrerId, $amount, $currency, ['user_id' => $userId]);
            } else {
                $processed[1] = ['success' => false, 'message' => 'Circular referral chain detected.'];
            }

            // 🛡️ H18 Fix: Use BCMath for tier2 multiplier calculation
            $tier2Multiplier = (string)$this->appSettings->get('referral_tier2_multiplier', '0.5');
            $referrer2 = $this->userModel->findById($referrerId);
            if ($referrer2 && $referrer2->referred_by) {
                $referrer2Id = (int)$referrer2->referred_by;
                if (!$this->detectCircularReferral($userId, $referrer2Id)) {
                    // ✅ PRECISE: Use BCMath multiplication
                    $tier2Amount = (string)\Core\ValueObjects\Money::fromString((string)((string)$amount))->multiply((string)($tier2Multiplier))->getAmount();
                    $processed[2] = $this->processCommission($referrer2Id, $tier2Amount, $currency, ['user_id' => $userId]);
                } else {
                    $processed[2] = ['success' => false, 'message' => 'Circular referral chain detected.'];
                }
            }
        }

        return ['tiers_processed' => $processed];
    }
}
