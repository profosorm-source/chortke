<?php

declare(strict_types=1);

namespace App\Services\Shared;

use App\Services\Notification\NotificationService;
use Core\Database;
use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\WalletService;
use App\Services\AuditTrail;
use App\Services\SettingService;

use App\Contracts\LoggerInterface;
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
        private WalletService $walletService,
        private NotificationService $notificationService,
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
        $trend = $this->db->query(
            "SELECT DATE(referred_at) as date, COUNT(*) as count, SUM(commission_amount) as total_commission
             FROM referral_commissions
             WHERE referrer_id = ? AND referred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY DATE(referred_at) ORDER BY date ASC",
            [$userId, $days]
        )->fetchAll() ?? [];

        return ['data' => $trend, 'period_days' => $days];
    }

    public function getConversionRate(int $userId, int $days = 30): array
    {
        $result = $this->db->query(
            "SELECT COUNT(DISTINCT referred_user_id) as converted,
                    COUNT(DISTINCT click_user_id) as clicked,
                    ROUND(100.0 * COUNT(DISTINCT referred_user_id) / NULLIF(COUNT(DISTINCT click_user_id), 0), 2) as conversion_rate
             FROM referral_clicks rc
             LEFT JOIN referral_commissions r ON rc.referred_user_id = r.referred_user_id
             WHERE rc.referrer_id = ? AND rc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$userId, $days]
        )->fetch();

        return [
            'converted' => $result->converted ?? 0,
            'clicked' => $result->clicked ?? 0,
            'rate' => $result->conversion_rate ?? 0,
        ];
    }

    public function getIndirectEarnings(int $userId, string $currency = 'irt'): float
    {
        $result = $this->db->query(
            "SELECT SUM(commission_amount) as total FROM referral_commissions rc
             WHERE rc.referrer_id IN (
                SELECT referred_user_id FROM referral_commissions WHERE referrer_id = ?
             ) AND rc.currency = ?",
            [$userId, $currency]
        )->fetch();

        return (float)($result->total ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Commission Processing
    // ═══════════════════════════════════════════════════════════════════════

    public function processCommission(int $referrerId, float $amount, string $currency, array $context = []): array
    {
        $percentage = (float) $this->settingService->get('referral_commission_percent', 5);
        $commission = $amount * ($percentage / 100);

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
                'idempotency_key' => "referral_{$referrerId}_" . time(),
            ]);

            $this->db->commit();
            return ['success' => true, 'commission' => $commission];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('commission_error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function processMultiTierCommissions(int $userId, float $amount, string $currency): array
    {
        $processed = [];
        $referrer = $this->db->query("SELECT referred_by FROM users WHERE id = ? LIMIT 1", [$userId])->fetch();

        if ($referrer && $referrer->referred_by) {
            $processed[1] = $this->processCommission($referrer->referred_by, $amount, $currency);

            $referrer2 = $this->db->query("SELECT referred_by FROM users WHERE id = ? LIMIT 1", [$referrer->referred_by])->fetch();
            if ($referrer2 && $referrer2->referred_by) {
                $processed[2] = $this->processCommission($referrer2->referred_by, $amount * 0.5, $currency);
            }
        }

        return ['tiers_processed' => $processed];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Leaderboard
    // ═══════════════════════════════════════════════════════════════════════

    public function getLeaderboard(int $limit = 50, string $period = 'month'): array
    {
        $dateFilter = match($period) {
            'week' => "DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'year' => "DATE_SUB(NOW(), INTERVAL 365 DAY)",
            default => "DATE_SUB(NOW(), INTERVAL 30 DAY)",
        };

        $leaderboard = $this->db->query(
            "SELECT u.id, u.username, COUNT(DISTINCT rc.referred_user_id) as referrals,
                    SUM(rc.commission_amount) as total_commission
             FROM users u
             LEFT JOIN referral_commissions rc ON u.id = rc.referrer_id
             WHERE rc.commission_date >= {$dateFilter}
             GROUP BY u.id ORDER BY total_commission DESC LIMIT ?",
            [$limit]
        )->fetchAll() ?? [];

        return array_map(fn($user, $rank) => (array)$user + ['rank' => $rank + 1], $leaderboard, array_keys($leaderboard));
    }

    public function distributeMonthlyRewards(): array
    {
        $top = $this->db->query(
            "SELECT u.id, SUM(rc.commission_amount) as total FROM users u
             LEFT JOIN referral_commissions rc ON u.id = rc.referrer_id
             WHERE MONTH(rc.commission_date) = MONTH(NOW()) GROUP BY u.id ORDER BY total DESC LIMIT 1"
        )->fetch();

        if ($top) {
            $bonus = $top->total * 0.05;
            $this->walletService->deposit($top->id, $bonus, 'irt', ['type' => 'referral_bonus']);
        }

        return ['bonus_percent' => 0.05];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Milestones
    // ═══════════════════════════════════════════════════════════════════════

    public function checkAndAwardMilestones(int $userId): array
    {
        $milestones = [
            ['name' => 'first_referral',   'condition' => 1,   'reward' => 50000],
            ['name' => 'ten_referrals',    'condition' => 10,  'reward' => 500000],
            ['name' => 'fifty_referrals',  'condition' => 50,  'reward' => 2000000],
            ['name' => 'hundred_referrals','condition' => 100, 'reward' => 5000000],
        ];

        $refCount = $this->db->query("SELECT COUNT(*) as count FROM referral_commissions WHERE referrer_id = ?", [$userId])->fetch()->count ?? 0;

        $awarded = [];
        foreach ($milestones as $milestone) {
            if ($refCount >= $milestone['condition']) {
                $existing = $this->db->query("SELECT id FROM user_milestones WHERE user_id = ? AND milestone = ? LIMIT 1", [$userId, $milestone['name']])->fetch();
                if (!$existing) {
                    $this->db->query("INSERT INTO user_milestones (user_id, milestone, awarded_at) VALUES (?, ?, NOW())", [$userId, $milestone['name']]);
                    $this->walletService->deposit($userId, $milestone['reward'], 'irt', ['type' => 'milestone_bonus']);
                    $awarded[] = $milestone['name'];
                }
            }
        }

        return ['awarded' => $awarded];
    }

    public function getUserAchievedMilestones(int $userId): array
    {
        return $this->db->query("SELECT * FROM user_milestones WHERE user_id = ? ORDER BY awarded_at DESC", [$userId])->fetchAll() ?? [];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Tier Management
    // ═══════════════════════════════════════════════════════════════════════

    public function getCurrentTier(int $userId): ?object
    {
        return $this->db->query("SELECT * FROM referral_tiers WHERE user_id = ? AND is_active = 1 LIMIT 1", [$userId])->fetch() ?: null;
    }

    public function checkAndUpgrade(int $userId): ?object
    {
        $refCount = $this->db->query("SELECT COUNT(*) as c FROM referral_commissions WHERE referrer_id = ? AND status = 'paid'", [$userId])->fetch()->c ?? 0;

        $tiers = [
            ['name' => 'bronze',   'min_referrals' => 5,   'bonus_percent' => 1],
            ['name' => 'silver',   'min_referrals' => 25,  'bonus_percent' => 2],
            ['name' => 'gold',     'min_referrals' => 100, 'bonus_percent' => 3],
            ['name' => 'platinum', 'min_referrals' => 500, 'bonus_percent' => 5],
        ];

        foreach (array_reverse($tiers) as $tier) {
            if ($refCount >= $tier['min_referrals']) {
                $current = $this->getCurrentTier($userId);
                if (!$current || $current->tier_name !== $tier['name']) {
                    $this->db->query("UPDATE referral_tiers SET is_active = 0 WHERE user_id = ?", [$userId]);
                    $this->db->query(
                        "INSERT INTO referral_tiers (user_id, tier_name, bonus_percent, is_active, upgraded_at) VALUES (?, ?, ?, 1, NOW())",
                        [$userId, $tier['name'], $tier['bonus_percent']]
                    );
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
        $result = $this->db->query("SELECT quality_score FROM user_quality_scores WHERE user_id = ? LIMIT 1", [$userId])->fetch();
        return $result ? (float)$result->quality_score : 50.0;
    }

    public function calculateScore(int $userId): float
    {
        $refCount = $this->db->query("SELECT COUNT(*) as c FROM referral_commissions WHERE referrer_id = ?", [$userId])->fetch()->c ?? 0;
        $convRate = $this->getConversionRate($userId)['rate'] ?? 0;
        $ageDays  = $this->db->query("SELECT DATEDIFF(NOW(), created_at) as days FROM users WHERE id = ? LIMIT 1", [$userId])->fetch()->days ?? 0;

        $score = 50 + min($refCount * 2, 25) + min($convRate, 15) + min($ageDays / 10, 10);

        $this->db->query(
            "INSERT INTO user_quality_scores (user_id, quality_score, last_updated) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE quality_score = ?, last_updated = NOW()",
            [$userId, $score, $score]
        );

        return $score;
    }

    public function penalizeScore(int $userId, int $points = 10, string $reason = ''): void
    {
        $this->db->query("UPDATE user_quality_scores SET quality_score = GREATEST(0, quality_score - ?) WHERE user_id = ?", [$points, $userId]);
        $this->auditTrail->log('score_penalized', "User $userId penalized: $reason", ['points' => $points]);
    }

    public function rewardScore(int $userId, int $points = 5, string $reason = ''): void
    {
        $this->db->query("UPDATE user_quality_scores SET quality_score = LEAST(100, quality_score + ?) WHERE user_id = ?", [$points, $userId]);
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

        $commissions = $this->db->query("SELECT * FROM referral_commissions WHERE status = 'pending' AND currency = ? ORDER BY created_at ASC LIMIT 100", [$currency])->fetchAll() ?? [];
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


