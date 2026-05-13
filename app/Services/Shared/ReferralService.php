<?php

declare(strict_types=1);

namespace App\Services\Shared;

use Core\Database;
use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\SettingService;

use App\Contracts\LoggerInterface;
use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
/**
 * ReferralService — سرویس اشتراکی سیستم رفرال
 *
 * جایگزین App\Services\ReferralService و App\Services\ReferralCommissionService می‌شود.
 */
class ReferralService extends \App\Services\BaseService
{
    public function __construct(
        private Database $db,
        protected LoggerInterface $logger,
        private WalletServiceInterface $walletService,
        private NotificationServiceInterface $notificationService,
        private AuditTrail $auditTrail,
        private ReferralCommission $commissionModel,
        private User $userModel,
        private SettingService $settingService
    ) {
        parent::__construct($logger);
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
        $percentage = (float) $this->settingService->get('referral_commission_percent', 5);
        $commission = (string)((float)$amount * ($percentage / 100));

        try {
            $this->db->beginTransaction();

            $this->commissionModel->create([
                'referrer_id' => $referrerId,
                'amount' => $amount,
                'commission_amount' => $commission,
                'currency' => $currency,
                'status' => 'pending',
                'context' => json_encode($context),
            ]);

            $this->walletService->deposit($referrerId, $commission, $currency, [
                'type' => 'referral_commission',
                'idempotency_key' => "referral_{$referrerId}_" . hash('sha256', json_encode($context)),
            ]);

            $this->db->commit();
            return ['success' => true, 'commission' => $commission];
        } catch (\Exception $e) {
            $this->db->rollBack();
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
                // اگر جدول influencer_profiles هنوز ساخته نشده یا با فیلد دیگری است
                $isInfluencer = false;
            }

            if ($isInfluencer) {
                $percentage = (float)$this->settingService->get('referral_influencer_pro_percent', 10.0);
            } else {
                $percentage = (float)$this->settingService->get('referral_influencer_regular_percent', 5.0);
            }
        } else {
            $settingKey = "referral_{$module}_percent";
            $percentage = (float)$this->settingService->get($settingKey, 5.0);
        }

        $commission = (string)((float)$amount * ($percentage / 100));

        try {
            $this->db->beginTransaction();

            $this->commissionModel->create([
                'referrer_id' => $referrerId,
                'amount' => $amount,
                'commission_amount' => $commission,
                'currency' => $currency,
                'status' => 'pending',
                'context' => json_encode(array_merge($context, [
                    'module' => $module,
                    'percentage' => $percentage,
                    'referred_user_id' => $referredUserId
                ])),
            ]);

            $this->walletService->deposit($referrerId, $commission, $currency, [
                'type' => 'referral_commission',
                'idempotency_key' => "referral_{$referrerId}_modular_" . hash('sha256', json_encode($context)),
            ]);

            $this->db->commit();
            return ['success' => true, 'commission' => $commission, 'percentage' => $percentage];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('modular_commission_error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function processMultiTierCommissions(int $userId, string $amount, string $currency): array
    {
        $processed = [];
        $referrer = $this->userModel->findById($userId);

        if ($referrer && $referrer->referred_by) {
            $processed[1] = $this->processCommission((int)$referrer->referred_by, $amount, $currency);

            $tier2Multiplier = (float)$this->settingService->get('referral_tier2_multiplier', 0.5);
            $referrer2 = $this->userModel->findById((int)$referrer->referred_by);
            if ($referrer2 && $referrer2->referred_by) {
                $tier2Amount = (string)((float)$amount * $tier2Multiplier);
                $processed[2] = $this->processCommission((int)$referrer2->referred_by, $tier2Amount, $currency);
            }
        }

        return ['tiers_processed' => $processed];
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
        $top = $this->commissionModel->getTopMonthlyReferrer();

        if ($top) {
            $bonusPercent = (float)$this->settingService->get('referral_top_bonus_percent', 5) / 100;
            $bonus = $top->total * $bonusPercent;
            $this->walletService->deposit((int)$top->id, $bonus, 'irt', ['type' => 'referral_bonus']);
            return ['bonus_percent' => $bonusPercent];
        }

        return ['bonus_percent' => 0.05];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Milestones
    // ═══════════════════════════════════════════════════════════════════════

    public function checkAndAwardMilestones(int $userId): array
    {
        $milestones = $this->settingService->get('referral_milestones', [
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
                    $this->walletService->deposit($userId, $milestone['reward'], 'irt', ['type' => 'milestone_bonus']);
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

        $tiers = $this->settingService->get('referral_tiers', [
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
        $base = (float) $this->settingService->get('referral_commission_percent', 5);
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
            $this->db->beginTransaction();
            if (!$this->commissionModel->updateStatus($commissionId, 'cancelled')) {
                throw new \RuntimeException('Unable to cancel referral commission');
            }
            $this->auditTrail->log('commission_cancelled', 'لغو کمیسیون توسط ادمین', ['commission_id' => $commissionId, 'reason' => $reason]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('referral.cancel_failed', ['commission_id' => $commissionId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function batchPay(string $currency): array
    {
        $currency = strtolower($currency);
        if (!in_array($currency, ['irt', 'usdt'], true)) return ['success' => false, 'message' => 'ارز نامعتبر'];

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

                $this->db->beginTransaction();
                $deposit = $this->walletService->deposit((int)$commission->referrer_id, (float)$commission->commission_amount, $currency, [
                    'type' => 'referral_commission',
                    'idempotency_key' => "referral_{$commission->id}_{$commission->referrer_id}",
                ]);

                if (empty($deposit['success'])) throw new \RuntimeException('Wallet deposit failed');

                $this->commissionModel->updateStatus((int)$commission->id, 'paid', $deposit['transaction_id'] ?? null);
                $this->db->commit();
                $results['success']++;
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $results['failed']++;
                $this->logger->error('referral.batch_pay_failed', ['commission_id' => $commission->id ?? null, 'error' => $e->getMessage()]);
            }
        }
        return $results;
    }
}


