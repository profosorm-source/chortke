<?php

declare(strict_types=1);

namespace App\Jobs\Referral;

class DistributeMonthlyReferralRewardsJob
{
    public function __construct(
        private \App\Models\ReferralCommission $commissionModel,
        private \App\Services\Settings\AppSettings $appSettings
    ) {}

    public function handle(): array
    {
        $top = $this->commissionModel->getTopMonthlyReferrer();

        if ($top) {
            $bonusPercent = (float)$this->appSettings->get('referral_top_bonus_percent', 5) / 100;
            $bonus = $top->total * $bonusPercent;
            $sysCurrency = strtolower((string)$this->appSettings->get('currency_mode', 'irt'));
            $targetCurrency = in_array($sysCurrency, ['irt', 'usdt'], true) ? $sysCurrency : 'irt';
            $this->eventDispatcher->dispatchAsync(\App\Events\Registry\EventRegistry::REFERRAL_COMMISSION_EARNED, [
                'user_id' => (int)$top->id,
                'amount' => $bonus,
                'currency' => $targetCurrency,
                'metadata' => [
                    'type' => 'referral_bonus',
                    'description' => 'Referral leaderboard bonus'
                ]
            ]);
            return ['bonus_percent' => $bonusPercent];
        }

        return ['bonus_percent' => 0.05];
    }
}
