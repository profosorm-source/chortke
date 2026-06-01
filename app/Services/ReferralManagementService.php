<?php

namespace App\Services;

use App\Models\ReferralCommission;
use App\Services\Shared\ReferralService;
use App\Services\User\UserService;
use Core\Database;

use App\Contracts\LoggerInterface;
class ReferralManagementService
{
        private \Core\Database $db;
        private ReferralService $referralService;
        private ReferralCommission $commissionModel;
        private UserService $userService;
        public function __construct(
        \Core\Database $db,
        ReferralService $referralService,
        ReferralCommission $commissionModel,
        UserService $userService
    ) {            $this->db = $db;
            $this->referralService = $referralService;
            $this->commissionModel = $commissionModel;
            $this->userService = $userService;

        
    }

    public function getDashboardData(int $leaderboardLimit = 10, string $currency = 'irt'): array
    {
        return [
            'globalStats' => $this->commissionModel->globalStats(),
            'tierStats' => $this->getGlobalTierStats(),
            'milestoneStats' => $this->getMilestoneStats(),
            'currentLeaderboard' => $this->referralService->getLeaderboard($leaderboardLimit, 'month'),
            'topReferrers' => $this->commissionModel->topReferrers($currency, $leaderboardLimit),
        ];
    }

    public function getAllTiers(): array
    {
        return $this->db->query('SELECT * FROM referral_tiers ORDER BY upgraded_at DESC')->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function getGlobalTierStats(): array
    {
        $rows = $this->db->query(
            'SELECT tier_name, COUNT(*) AS users, AVG(bonus_percent) AS avg_bonus
             FROM referral_tiers
             WHERE is_active = 1
             GROUP BY tier_name'
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];

        return array_map(fn($row) => (array)$row, $rows);
    }

    public function getActiveMilestones(): array
    {
        return $this->db->query(
            'SELECT * FROM referral_milestones WHERE is_active = TRUE ORDER BY milestone_type ASC, threshold_value ASC'
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function getMilestoneStats(): array
    {
        return $this->db->query(
            'SELECT milestone_type, COUNT(*) AS total, SUM(threshold_value) AS total_threshold
             FROM referral_milestones
             WHERE is_active = TRUE
             GROUP BY milestone_type'
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function getLeaderboard(string $periodKey, int $limit = 100): array
    {
        if (preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            return $this->getMonthlyLeaderboard($periodKey, $limit);
        }

        if (preg_match('/^\d{4}$/', $periodKey)) {
            return $this->getYearlyLeaderboard($periodKey, $limit);
        }

        return $this->referralService->getLeaderboard($limit, $periodKey === 'week' || $periodKey === 'year' ? $periodKey : 'month');
    }

    private function getMonthlyLeaderboard(string $periodKey, int $limit): array
    {
        [$year, $month] = explode('-', $periodKey);
        $sql = "SELECT u.id, u.full_name, u.email, COUNT(DISTINCT rc.referred_user_id) AS referrals, SUM(rc.commission_amount) AS total_commission
                FROM users u
                LEFT JOIN referral_commissions rc
                  ON u.id = rc.referrer_id
                 AND DATE_FORMAT(rc.commission_date, '%Y-%m') = ?
                WHERE rc.status = 'paid'
                GROUP BY u.id
                ORDER BY total_commission DESC
                LIMIT ?";

        $stmt = $this->db->query($sql, [$periodKey, $limit]);
        return $stmt ? ($stmt->fetchAll(\PDO::FETCH_OBJ) ?: []) : [];
    }

    private function getYearlyLeaderboard(string $year, int $limit): array
    {
        $sql = "SELECT u.id, u.full_name, u.email, COUNT(DISTINCT rc.referred_user_id) AS referrals, SUM(rc.commission_amount) AS total_commission
                FROM users u
                LEFT JOIN referral_commissions rc
                  ON u.id = rc.referrer_id
                 AND DATE_FORMAT(rc.commission_date, '%Y') = ?
                WHERE rc.status = 'paid'
                GROUP BY u.id
                ORDER BY total_commission DESC
                LIMIT ?";

        $stmt = $this->db->query($sql, [$year, $limit]);
        return $stmt ? ($stmt->fetchAll(\PDO::FETCH_OBJ) ?: []) : [];
    }

    public function updateCurrentLeaderboard(int $limit = 100): int
    {
        return count($this->referralService->getLeaderboard($limit, 'month'));
    }

    public function distributeMonthlyRewards(): array
    {
        return $this->referralService->distributeMonthlyRewards();
    }

    public function getReferrerDetails(int $userId): ?array
    {
        $user = $this->userService->find($userId);
        if (!$user) {
            return null;
        }

        return [
            'user' => $user,
            'stats' => $this->commissionModel->getReferrerStats($userId),
            'currentTier' => $this->referralService->getCurrentTier($userId),
            'tierHistory' => $this->getUserTierHistory($userId),
            'qualityScore' => $this->referralService->getScore($userId),
            'qualityInterpretation' => $this->getQualityScoreInterpretation($this->referralService->getScore($userId)),
            'achievedMilestones' => $this->referralService->getUserAchievedMilestones($userId),
            'analytics' => $this->getReferrerDashboard($userId),
        ];
    }

    public function getReferrerDashboard(int $userId): array
    {
        return [
            'trend' => $this->referralService->getReferralTrend($userId),
            'conversionRate' => $this->referralService->getConversionRate($userId),
            'indirectEarnings' => $this->referralService->getIndirectEarnings($userId),
        ];
    }

    public function getUserTierHistory(int $userId): array
    {
        return $this->db->query(
            'SELECT * FROM referral_tiers WHERE user_id = ? ORDER BY upgraded_at DESC',
            [$userId]
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    public function checkTierUpgrade(int $userId): ?object
    {
        return $this->referralService->checkAndUpgrade($userId);
    }

    public function checkMilestones(int $userId): array
    {
        $result = $this->referralService->checkAndAwardMilestones($userId);
        return $result['awarded'] ?? [];
    }

    public function recalculateQualityScore(int $userId): float
    {
        return $this->referralService->calculateScore($userId);
    }

    public function adjustQualityScore(int $userId, string $action, float $amount, string $reason): void
    {
        if ($action === 'reward') {
            $this->referralService->rewardScore($userId, (int)$amount, $reason);
            return;
        }

        $this->referralService->penalizeScore($userId, (int)$amount, $reason);
    }

    public function getQualityScoreInterpretation(float $score): string
    {
        return match (true) {
            $score >= 80 => 'عالی',
            $score >= 60 => 'خوب',
            $score >= 40 => 'متوسط',
            default => 'نیاز به بهبود',
        };
    }

    public function getQualityScoreReport(int $page = 1, int $limit = 50): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $stmt = $this->db->query(
            "SELECT \
                u.id, u.full_name, u.email, u.referral_quality_score, \
                COUNT(DISTINCT ref.id) as total_referrals, \
                COUNT(DISTINCT CASE WHEN ref.status = 'active' THEN ref.id END) as active_referrals \
             FROM users u \
             LEFT JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL \
             WHERE u.deleted_at IS NULL \
             GROUP BY u.id \
             HAVING total_referrals > 0 \
             ORDER BY u.referral_quality_score DESC \
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );

        $users = $stmt ? ($stmt->fetchAll(\PDO::FETCH_OBJ) ?: []) : [];
        $total = (int) ($this->db->query(
            'SELECT COUNT(DISTINCT u.id) as total FROM users u JOIN users ref ON ref.referred_by = u.id AND ref.deleted_at IS NULL WHERE u.deleted_at IS NULL',
            []
        )->fetch(\PDO::FETCH_OBJ)->total ?? 0);

        return [
            'users' => $users,
            'page' => $page,
            'total' => $total,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit),
        ];
    }

    public function batchRecalculateQuality(int $limit = 100): int
    {
        $rows = $this->db->query(
            'SELECT id FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT ?',
            [$limit]
        )->fetchAll(\PDO::FETCH_OBJ) ?: [];

        foreach ($rows as $row) {
            $this->referralService->calculateScore((int)$row->id);
        }

        return count($rows);
    }
}

