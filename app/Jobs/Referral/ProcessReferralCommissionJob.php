<?php

declare(strict_types=1);

namespace App\Jobs\Referral;

class ProcessReferralCommissionJob
{
    private \App\Services\Settings\AppSettings $appSettings;
    private \Core\Database $db;
    private \App\Models\ReferralCommission $commissionModel;
    private \App\Contracts\WalletServiceInterface $walletService;
    private \App\Contracts\LoggerInterface $logger;
    private \Core\TransactionWrapper $transactionWrapper;
    public function __construct(
        \App\Services\Settings\AppSettings $appSettings,
        \Core\Database $db,
        \App\Models\ReferralCommission $commissionModel,
        \App\Contracts\WalletServiceInterface $walletService,
        \App\Contracts\LoggerInterface $logger,
        \Core\TransactionWrapper $transactionWrapper
    ) {        $this->appSettings = $appSettings;
        $this->db = $db;
        $this->commissionModel = $commissionModel;
        $this->walletService = $walletService;
        $this->logger = $logger;
        $this->transactionWrapper = $transactionWrapper;
}

    public function handle(int $referrerId, string $amount, string $currency, array $context = []): array
    {
        // 🛡️ H18 Fix: Use BCMath for precise financial calculations instead of float arithmetic
        $percentage = (string)$this->appSettings->get('referral_commission_percent', 5);
        
        // ✅ PRECISE CALCULATION: Use BCMath for commission computation
        $commissionRatio = bcdiv((string)$percentage, '100', 8);
        $commission = \Core\ValueObjects\Money::fromString((string)((string)$amount))->multiply((string)($commissionRatio))->getAmount();
        $commission = bcdiv($commission, '1', 2); // Round to 2 decimal places

        // H-R3: Self-referral check
        if ($referrerId === (int)($context['investor_id'] ?? 0) || $referrerId === (int)($context['user_id'] ?? 0)) {
            return ['success' => false, 'message' => 'امکان واریز پورسانت به خود وجود ندارد.'];
        }

        // R-1: Circular chain check
        $investorId = (int)($context['investor_id'] ?? $context['user_id'] ?? 0);
        if ($investorId > 0 && $this->detectCircularReferral($investorId, $referrerId)) {
            return ['success' => false, 'message' => 'Circular referral chain detected.'];
        }

        // R-3: Rate Limit Check (throttling daily commission payout rate per referrer)
        $rateLimitKey = "ref_commission_limit:" . date('Y-m-d') . ":" . $referrerId;
        $dailyCount = \Core\Cache::getInstance()->increment($rateLimitKey, 1, 86400);
        $dailyMax = (int)$this->appSettings->get('referral_daily_limit', 50);
        if ($dailyCount !== false && $dailyCount > $dailyMax) {
            return ['success' => false, 'message' => 'محدودیت تعداد پورسانت‌های روزانه برای این معرف به پایان رسیده است.'];
        }

        try {
            return $this->transactionWrapper->runWithRetry(function() use ($referrerId, $amount, $currency, $commission, $percentage, $context) {
                $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$referrerId]);

                $commissionIdempotencyKey = $context['idempotency_key'] ?? "referral_{$referrerId}_" . hash('sha256', json_encode($context));

                $existingCommission = $this->commissionModel->findByIdempotencyKey($commissionIdempotencyKey);
                if ($existingCommission) {
                    return ['success' => true, 'commission' => (float)$existingCommission->commission_amount, 'duplicate' => true];
                }

                $this->commissionModel->create([
                    'referrer_id' => $referrerId,
                    'amount' => $amount,
                    'commission_amount' => $commission,
                    'currency' => $currency,
                    'status' => 'paid',
                    'idempotency_key' => $commissionIdempotencyKey,
                    'context' => json_encode(array_merge($context, [
                        'percentage' => $percentage,
                    ])),
                ]);

                $this->walletService->depositInTransaction($referrerId, (float)$commission, $currency, [
                    'type' => 'referral_commission',
                    'description' => 'کمیسیون معرفی',
                    'idempotency_key' => $commissionIdempotencyKey,
                ]);

                return ['success' => true, 'commission' => $commission];
            });
        } catch (\Exception $e) {
            $this->logger->error('commission_error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
