<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Settings\AppSettings;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use Core\EventDispatcher;
/**
 * ReferralService — سرویس اشتراکی سیستم رفرال
 *
 * جایگزین App\Services\ReferralService و App\Services\ReferralCommissionService می‌شود.
 */
class ReferralService
{
    private ?\App\Contracts\OutboxServiceInterface $outboxService = null;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private WalletServiceInterface $walletService;
    private AuditTrail $auditTrail;
    private ReferralCommission $commissionModel;
    private User $userModel;
    private AppSettings $appSettings;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        WalletServiceInterface $walletService,
        AuditTrail $auditTrail,
        ReferralCommission $commissionModel,
        User $userModel,
        AppSettings $appSettings,
        ?\App\Contracts\OutboxServiceInterface $outboxService = null
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;
        $this->walletService = $walletService;
        $this->auditTrail = $auditTrail;
        $this->commissionModel = $commissionModel;
        $this->userModel = $userModel;
        $this->appSettings = $appSettings;

        
        $this->outboxService = $outboxService;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Analytics
    // ═══════════════════════════════════════════════════════════════════════

    public function getReferralTrend(int $userId, int $days = 30): array
    {
        $trend = $this->commissionModel->getReferralTrend($userId, $days);
        return ['data' => $trend, 'period_days' => $days];
    }

    public function getConversionRate(int $userId, int $days = 30): array
    {
        $result = $this->commissionModel->getConversionRate($userId, $days);
        return [
            'converted' => $result->converted ?? 0,
            'clicked' => $result->clicked ?? 0,
            'rate' => $result->conversion_rate ?? 0,
        ];
    }

    public function getIndirectEarnings(int $userId, string $currency = 'irt'): float
    {
        return $this->commissionModel->getIndirectEarnings($userId, $currency);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Commission Processing
    // ═══════════════════════════════════════════════════════════════════════

    public function processCommission(int $referrerId, string $amount, string $currency, array $context = []): array
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
            return $this->getTransactionWrapper()->runWithRetry(function() use ($referrerId, $amount, $currency, $commission, $percentage, $context) {
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

    /**
     * پردازش پورسانت داینامیک و تفکیک‌شده بر اساس نوع ماژول و نقش معرف
     */
    public function processModularCommission(int $referredUserId, string $module, string $amount, string $currency, array $context = []): array
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
            return $this->getTransactionWrapper()->runWithRetry(function() use ($referrerId, $amount, $currency, $commission, $percentage, $module, $referredUserId, $context) {
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

    public function processMultiTierCommissions(int $userId, string $amount, string $currency): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Referral\ProcessMultiTierReferralCommissionsJob::class);
        return $job->handle($userId, $amount, $currency);
    }


    // ═══════════════════════════════════════════════════════════════════════
    // Leaderboard
    // ═══════════════════════════════════════════════════════════════════════

    public function getLeaderboard(int $limit = 50, string $period = 'month'): array
    {
        $days = match($period) {
            'week' => 7,
            'year' => 365,
            default => 30,
        };

        $leaderboard = $this->commissionModel->getLeaderboard($days, $limit);

        return array_map(fn($user, $rank) => (array)$user + ['rank' => $rank + 1], $leaderboard, array_keys($leaderboard));
    }

    public function distributeMonthlyRewards(): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Referral\DistributeMonthlyReferralRewardsJob::class);
        return $job->handle();
    }


    // ═══════════════════════════════════════════════════════════════════════
    // Milestones
    // ═══════════════════════════════════════════════════════════════════════

    public function checkAndAwardMilestones(int $userId): array
    {
        $milestones = $this->appSettings->get('referral_milestones', [
            ['name' => 'first_referral',   'condition' => 1,   'reward' => 50000],
            ['name' => 'ten_referrals',    'condition' => 10,  'reward' => 500000],
            ['name' => 'fifty_referrals',  'condition' => 50,  'reward' => 2000000],
            ['name' => 'hundred_referrals','condition' => 100, 'reward' => 5000000],
        ]);

        $refCount = $this->commissionModel->where('referrer_id', '=', $userId)->count();

        $awarded = [];
        foreach ($milestones as $milestone) {
            if ($refCount >= $milestone['condition']) {
                $existing = $this->db->table('user_milestones')
                    ->where('user_id', '=', $userId)
                    ->where('milestone', '=', $milestone['name'])
                    ->first();
                if (!$existing) {
                    $this->db->table('user_milestones')->insert([
                        'user_id' => $userId,
                        'milestone' => $milestone['name'],
                        'awarded_at' => date('Y-m-d H:i:s')
                    ]);
                    $sysCurrency = strtolower((string)$this->appSettings->get('currency_mode', 'irt'));
                    $targetCurrency = in_array($sysCurrency, ['irt', 'usdt'], true) ? $sysCurrency : 'irt';
                    $this->eventDispatcher->dispatchAsync(\App\Events\Registry\EventRegistry::REFERRAL_COMMISSION_EARNED, [
                        'user_id' => $userId,
                        'amount' => $milestone['reward'],
                        'currency' => $targetCurrency,
                        'metadata' => [
                            'type' => 'milestone_bonus',
                            'milestone' => $milestone['name'],
                            'description' => 'Referral milestone reward'
                        ]
                    ]);
                    $awarded[] = $milestone['name'];
                }
            }
        }

        return ['awarded' => $awarded];
    }

    public function getUserAchievedMilestones(int $userId): array
    {
        return $this->db->table('user_milestones')
            ->where('user_id', '=', $userId)
            ->orderBy('awarded_at', 'DESC')
            ->get() ?? [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Tier Management
    // ═══════════════════════════════════════════════════════════════════════

    public function getCurrentTier(int $userId): ?object
    {
        return $this->db->table('referral_tiers')
            ->where('user_id', '=', $userId)
            ->where('is_active', '=', 1)
            ->first();
    }

    public function checkAndUpgrade(int $userId): ?object
    {
        $refCount = $this->commissionModel
            ->where('referrer_id', '=', $userId)
            ->where('status', '=', 'paid')
            ->count();

        $tiers = $this->appSettings->get('referral_tiers', [
            ['name' => 'bronze',   'min_referrals' => 5,   'bonus_percent' => 1],
            ['name' => 'silver',   'min_referrals' => 25,  'bonus_percent' => 2],
            ['name' => 'gold',     'min_referrals' => 100, 'bonus_percent' => 3],
            ['name' => 'platinum', 'min_referrals' => 500, 'bonus_percent' => 5],
        ]);

        foreach (array_reverse($tiers) as $tier) {
            if ($refCount >= $tier['min_referrals']) {
                $current = $this->getCurrentTier($userId);
                if (!$current || $current->tier_name !== $tier['name']) {
                    $this->db->table('referral_tiers')
                        ->where('user_id', '=', $userId)
                        ->update(['is_active' => 0]);
                    $this->db->table('referral_tiers')->insert([
                        'user_id' => $userId,
                        'tier_name' => $tier['name'],
                        'bonus_percent' => $tier['bonus_percent'],
                        'is_active' => 1,
                        'upgraded_at' => date('Y-m-d H:i:s')
                    ]);
                    return $this->getCurrentTier($userId);
                }
            }
        }

        return $this->getCurrentTier($userId);
    }

    public function calculateFinalCommissionPercent(int $userId): float
    {
        $base = (float) $this->appSettings->get('referral_commission_percent', 5);
        $tier = $this->getCurrentTier($userId);
        return $base + ($tier ? (float)$tier->bonus_percent : 0);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Quality Score
    // ═══════════════════════════════════════════════════════════════════════

    public function getScore(int $userId): float
    {
        $result = $this->db->table('user_quality_scores')
            ->where('user_id', '=', $userId)
            ->first();
        return $result ? (float)$result->quality_score : 50.0;
    }

    public function calculateScore(int $userId): float
    {
        $refCount = $this->commissionModel->where('referrer_id', '=', $userId)->count();
        $convRate = $this->getConversionRate($userId)['rate'] ?? 0;
        
        $user = $this->userModel->findById($userId);
        $ageDays = 0;
        if ($user && isset($user->created_at)) {
            $ageDays = (int)floor((time() - strtotime($user->created_at)) / 86400);
        }

        $score = 50 + min($refCount * 2, 25) + min($convRate, 15) + min($ageDays / 10, 10);

        $existing = $this->db->table('user_quality_scores')
            ->where('user_id', '=', $userId)
            ->first();
        if ($existing) {
            $this->db->table('user_quality_scores')
                ->where('user_id', '=', $userId)
                ->update([
                    'quality_score' => $score,
                    'last_updated' => date('Y-m-d H:i:s')
                ]);
        } else {
            $this->db->table('user_quality_scores')->insert([
                'user_id' => $userId,
                'quality_score' => $score,
                'last_updated' => date('Y-m-d H:i:s')
            ]);
        }

        return $score;
    }

    public function penalizeScore(int $userId, int $points = 10, string $reason = ''): void
    {
        $existing = $this->db->table('user_quality_scores')->where('user_id', '=', $userId)->first();
        if ($existing) {
            $newScore = max(0, (float)$existing->quality_score - $points);
            $this->db->table('user_quality_scores')
                ->where('user_id', '=', $userId)
                ->update(['quality_score' => $newScore, 'last_updated' => date('Y-m-d H:i:s')]);
        }
        $this->auditTrail->log('score_penalized', "User $userId penalized: $reason", ['points' => $points]);
    }

    public function rewardScore(int $userId, int $points = 5, string $reason = ''): void
    {
        $existing = $this->db->table('user_quality_scores')->where('user_id', '=', $userId)->first();
        if ($existing) {
            $newScore = min(100, (float)$existing->quality_score + $points);
            $this->db->table('user_quality_scores')
                ->where('user_id', '=', $userId)
                ->update(['quality_score' => $newScore, 'last_updated' => date('Y-m-d H:i:s')]);
        }
        $this->auditTrail->log('score_rewarded', "User $userId rewarded: $reason", ['points' => $points]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Admin Operations
    // ═══════════════════════════════════════════════════════════════════════

    public function getSourceTypes(): array
    {
        return [
            'task' => 'تکلیف',
            'investment' => 'سرمایه‌گذاری',
            'vip' => 'VIP',
            'story' => 'داستان',
        ];
    }

    public function getSourceLabel(?string $type): string
    {
        return $this->getSourceTypes()[$type] ?? 'ناشناخته';
    }

    public function saveSettings(array $settings): bool
    {
        if (empty($settings)) return true;

        $stmt = $this->db->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
        if (!$stmt) return false;

        foreach ($settings as $key => $value) {
            $stmt->execute([(string)$value, $key]);
        }
        return true;
    }

    public function cancelCommission(int $commissionId, string $reason): bool
    {
        $commission = $this->commissionModel->find($commissionId);
        if (!$commission || $commission->status !== 'pending') return false;

        try {
            $this->getTransactionWrapper()->runWithRetry(function() use ($commissionId, $reason) {
                if (!$this->commissionModel->updateStatus($commissionId, 'cancelled')) {
                    throw new \RuntimeException('Unable to cancel referral commission');
                }
                $this->auditTrail->log('commission_cancelled', 'لغو کمیسیون توسط ادمین', ['commission_id' => $commissionId, 'reason' => $reason]);
            });
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('referral.cancel_failed', ['commission_id' => $commissionId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function batchPay(string $currency): array
    {
        $currency = strtolower($currency);
        if (!in_array($currency, ['irt', 'usdt'], true)) return ['success' => false, 'message' => 'ارز نامعتبر'];

        // R-4: Idempotency locking for batch pay
        $lockKey = "referral_batch_pay_lock_" . $currency;
        $lock = \Core\Cache::getInstance()->lock($lockKey, 300); // 5-minute timeout
        if (!$lock) {
            return ['success' => 0, 'failed' => 0, 'skipped' => 0, 'locked' => true];
        }

        try {
            $commissions = $this->commissionModel
                ->where('status', '=', 'pending')
                ->where('currency', '=', $currency)
                ->orderBy('created_at', 'ASC')
                ->limit(100)
                ->get() ?? [];
            $results = ['success' => 0, 'failed' => 0, 'skipped' => 0];

            foreach ($commissions as $commission) {
                try {
                    if (!isset($commission->referrer_id, $commission->commission_amount)) {
                        $results['skipped']++;
                        continue;
                    }

                    $this->getTransactionWrapper()->runWithRetry(function() use ($commission, $currency) {
                        $payload = [
                            'user_id' => (int)$commission->referrer_id,
                            'amount' => (float)$commission->commission_amount,
                            'currency' => $currency,
                            'metadata' => [
                                'type' => 'referral_commission',
                                'idempotency_key' => "referral_{$commission->id}_{$commission->referrer_id}",
                                'commission_id' => $commission->id,
                            ],
                        ];

                        if (isset($this->outboxService) && $this->outboxService) {
                            $ok = $this->outboxService->record('referral_commission', (int)$commission->id, \App\Events\Registry\EventRegistry::REFERRAL_COMMISSION_EARNED, $payload);
                            if (!$ok) throw new \RuntimeException('Wallet outbox record failed');

                            // mark paid; transaction id will be filled by the Wallet deposit consumer later
                            $this->commissionModel->updateStatus((int)$commission->id, 'paid', null);
                        } else {
                            $deposit = $this->walletService->deposit((int)$commission->referrer_id, (float)$commission->commission_amount, $currency, [
                                'type' => 'referral_commission',
                                'idempotency_key' => "referral_{$commission->id}_{$commission->referrer_id}",
                            ]);

                            if (empty($deposit['success'])) throw new \RuntimeException('Wallet deposit failed');

                            $this->commissionModel->updateStatus((int)$commission->id, 'paid', $deposit['transaction_id'] ?? null);
                        }
                    });
                    $results['success']++;
                } catch (\Throwable $e) {
                    $results['failed']++;
                    $this->logger->error('referral.batch_pay_failed', ['commission_id' => $commission->id ?? null, 'error' => $e->getMessage()]);
                }
            }
            return $results;
        } finally {
            \Core\Cache::getInstance()->unlock($lockKey);
        }
    }

    /**
     * بررسی گراف معرف‌ها برای یافتن حلقه‌های چرخشی (ممانعت از Circular Referral Chain Attack)
     */
    public function detectCircularReferral(int $userId, int $proposedReferrerId, int $maxDepth = 10): bool
    {
        if ($userId === $proposedReferrerId) {
            return true;
        }

        $currentReferrerId = $proposedReferrerId;
        $depth = 0;

        while ($currentReferrerId > 0 && $depth < $maxDepth) {
            if ($currentReferrerId === $userId) {
                return true;
            }

            $user = $this->userModel->findById($currentReferrerId);
            if (!$user || empty($user->referred_by)) {
                break;
            }

            $currentReferrerId = (int)$user->referred_by;
            $depth++;
        }

        return false;
    }
}


