<?php

declare(strict_types=1);

namespace App\Jobs\Referral;

class ProcessModularReferralCommissionJob
{
    public function __construct(
        private \App\Models\User $userModel,
        private \App\Services\Settings\AppSettings $appSettings,
        private \Core\Database $db,
        private \App\Contracts\LoggerInterface $logger,
        private \App\Models\ReferralCommission $commissionModel,
        private \App\Contracts\WalletServiceInterface $walletService,
        private \Core\TransactionWrapper $transactionWrapper
    ) {}

    public function handle(int $referredUserId, string $module, string $amount, string $currency, array $context = []): array
    {
        // پیدا کردن معرف کاربر
        $referrerUser = $this->userModel->findById($referredUserId);
        if (!$referrerUser || !$referrerUser->referred_by) {
            return ['success' => true, 'commission' => 0.0, 'message' => 'No referrer found'];
        }

        $referrerId = (int)$referrerUser->referred_by;

        // H-R3: Self-referral check
        if ($referrerId === (int)$referredUserId) {
            return ['success' => false, 'message' => 'Self-referral detected'];
        }

        // R-1: Circular chain check
        if ($this->detectCircularReferral((int)$referredUserId, $referrerId)) {
            return ['success' => false, 'message' => 'Circular referral chain detected.'];
        }

        // R-3: Rate Limit Check (throttling daily commission payout rate per referrer)
        $rateLimitKey = "ref_commission_limit:" . date('Y-m-d') . ":" . $referrerId;
        $dailyCount = \Core\Cache::getInstance()->increment($rateLimitKey, 1, 86400);
        $dailyMax = (int)$this->appSettings->get('referral_daily_limit', 50);
        if ($dailyCount !== false && $dailyCount > $dailyMax) {
            return ['success' => false, 'message' => 'محدودیت تعداد پورسانت‌های روزانه برای این معرف به پایان رسیده است.'];
        }

        // دریافت درصد پورسانت بر اساس نوع ماژول
        if ($module === 'influencer') {
            // بررسی اینکه آیا معرف خودش به عنوان اینفلوئنسر ثبت‌نام شده یا خیر
            $isInfluencer = false;
            try {
                $count = (int)$this->db->table('influencer_profiles')
                    ->where('user_id', '=', $referrerId)
                    ->where('status', '=', 'approved')
                    ->count();
                $isInfluencer = $count > 0;
            } catch (\Throwable $t) {
                // H-R6 Fix: Silent fail is dangerous. Log it at least. 
                // However, in this system, if the table is missing, it's a structural error.
                $this->logger->error('influencer_check_failed', ['error' => $t->getMessage()]);
                $isInfluencer = false; 
            }

            if ($isInfluencer) {
                $percentage = (float)$this->appSettings->get('referral_influencer_pro_percent', 10.0);
            } else {
                $percentage = (float)$this->appSettings->get('referral_influencer_regular_percent', 5.0);
            }
        } else {
            $settingKey = "referral_{$module}_percent";
            $percentage = (float)$this->appSettings->get($settingKey, 5.0);
        }

        $commission = \Core\ValueObjects\Money::fromString((string)((string)$amount))->multiply((string)(bcdiv((string)$percentage))->getAmount(), 2);

        try {
            return $this->transactionWrapper->runWithRetry(function() use ($referrerId, $amount, $currency, $commission, $percentage, $module, $referredUserId, $context) {
                $this->db->query("SELECT id FROM users WHERE id = ? FOR UPDATE", [$referrerId]);

                $commissionIdempotencyKey = $context['idempotency_key'] ?? "referral_{$referrerId}_modular_" . hash('sha256', json_encode($context));

                $existingCommission = $this->commissionModel->findByIdempotencyKey($commissionIdempotencyKey);
                if ($existingCommission) {
                    return ['success' => true, 'commission' => (float)$existingCommission->commission_amount, 'percentage' => $percentage, 'duplicate' => true];
                }

                $this->commissionModel->create([
                    'referrer_id' => $referrerId,
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'currency' => $currency,
                    'status' => 'paid',
                    'idempotency_key' => $commissionIdempotencyKey,
                    'context' => json_encode(array_merge($context, [
                        'module' => $module,
                        'percentage' => $percentage,
                        'referred_user_id' => $referredUserId
                    ])),
                ]);

                $this->walletService->depositInTransaction($referrerId, (float)$commission, $currency, [
                    'type' => 'referral_commission',
                    'idempotency_key' => $commissionIdempotencyKey,
                ]);

                return ['success' => true, 'commission' => $commission, 'percentage' => $percentage];
            });
        } catch (\Exception $e) {
            $this->logger->error('modular_commission_error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
