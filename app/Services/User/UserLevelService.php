<?php
declare(strict_types=1);

namespace App\Services\User;

use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Models\User;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\ReferralService as ReferralCommissionService;
use Core\EventDispatcher;
use App\Services\ScoreService;
use App\Enums\ModuleContext;
use App\Services\User\UserService;

class UserLevelService
{
    private WalletServiceInterface $walletService;
    private ReferralCommissionService $commissionService;
    private UserLevel $levelModel;
    private UserLevelHistory $historyModel;
    private AppSettings $appSettings;
    private ScoreService $scoreService;
    private UserService $userService;

    private \Core\TransactionWrapper $transactionWrapper;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\TransactionWrapper $transactionWrapper,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        UserLevel $levelModel,
        AppSettings $appSettings,
        ScoreService $scoreService
    ) {        $this->transactionWrapper = $transactionWrapper;
        $this->db = $db;
        $this->logger = $logger;

        $this->levelModel = $levelModel;
        
        
        
        $this->appSettings = $appSettings;
        $this->scoreService = $scoreService;
        
    }

    public function recordDailyActivity(int $userId): void
    {
        if (!$this->isEnabled()) return;

        $today = \date('Y-m-d');
        $currentMonth = \date('Y-m');

        try {
            $this->transactionWrapper->runWithRetry(function() use ($userId, $today) {
                $stmt = $this->db->prepare("SELECT last_active_date FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute([$userId]);
                $userRow = $stmt->fetch(\PDO::FETCH_OBJ);

                if (!$userRow || $userRow->last_active_date === $today) {
                    return;
                }

                $stmt = $this->db->prepare("UPDATE users SET last_active_date = ? WHERE id = ?");
                $stmt->execute([$today, $userId]);
            });

            if ($this->appSettings->get('level_activity_upgrade_enabled', 1)) {
                $this->checkUpgrade($userId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('level.record_daily_activity.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function recordTaskCompletion(int $userId, float $earnedAmount, string $currency = 'irt'): void
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\User\RecordLevelTaskCompletionJob::class);
        $job->handle($userId, $earnedAmount, $currency);
    }

    public function checkUpgrade(int $userId): ?string
    {
        try {
            return $this->transactionWrapper->runWithRetry(function() use ($userId) {
                $stmt = $this->db->prepare("
                    SELECT level_slug, level_type, level_expires_at
                    FROM users 
                    WHERE id = ? AND deleted_at IS NULL
                    FOR UPDATE
                ");
                $stmt->execute([$userId]);
                $userRow = $stmt->fetch(\PDO::FETCH_OBJ);

                $stmtHist = $this->db->prepare("SELECT id FROM user_level_histories WHERE user_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $stmtHist->execute([$userId]);
                $stmtHist->fetch();

                if (!$userRow) {
                    return null;
                }

                if ($userRow->level_type === 'purchased') {
                    if ($userRow->level_expires_at && \strtotime($userRow->level_expires_at) > \time()) {
                        return null;
                    }
                }
                
                $totalScore = $this->scoreService->getScore('user', $userId, 'score_' . ModuleContext::GLOBAL->value);

                $eligible = $this->levelModel->getEligibleLevel($totalScore);

                if (!$eligible) {
                    return null;
                }

                $currentLevel = $this->levelModel->findBySlug($userRow->level_slug);
                if (!$currentLevel) {
                    return null;
                }

                if ($eligible->sort_order <= $currentLevel->sort_order) {
                    return null;
                }

                $this->changeLevel($userId, $userRow->level_slug, $eligible->slug, 'upgrade', 'ارتقا بر اساس امتیاز سیستم (Gamification)');

                $this->logger->info('User level upgraded by score', [
                    'user_id' => $userId,
                    'from' => $userRow->level_slug,
                    'to' => $eligible->slug,
                ]);

                return $eligible->slug;
            });
        } catch (\Exception $e) {
            $this->logger->error('level.upgrade_check.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function purchaseLevel(int $userId, string $levelSlug, string $currency = 'irt'): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\User\PurchaseUserLevelJob::class);
        return $job->handle($userId, $levelSlug, $currency);
    }

    public function checkDowngrades(): array
    {
        if (!$this->isEnabled()) return ['checked' => 0, 'downgraded' => 0];

        $inactiveDaysThreshold = (int) $this->appSettings->get('level_downgrade_inactive_days', 3);
        $currentMonth = \date('Y-m');
        $daysInMonth = (int) \date('t');
        $today = (int) \date('j');

        if ($today < 25) {
            return ['checked' => 0, 'downgraded' => 0, 'reason' => 'too_early'];
        }

        $results = ['checked' => 0, 'downgraded' => 0];
        $maxInactive = $daysInMonth - $inactiveDaysThreshold;

        $stmt = $this->db->prepare("
            SELECT id, level_slug, monthly_active_days, full_name
            FROM users
            WHERE deleted_at IS NULL
            AND level_type = 'activity'
            AND level_slug != 'bronze'
            AND monthly_active_days < ?
        ");
        $stmt->execute([$inactiveDaysThreshold]);
        $inactiveUsers = $stmt->fetchAll(\PDO::FETCH_OBJ);

        foreach ($inactiveUsers as $user) {
            $results['checked']++;
            $this->changeLevel(
                $user->id,
                $user->level_slug,
                'bronze',
                'downgrade',
                "فعالیت ماهانه: {$user->monthly_active_days} روز (حداقل: {$inactiveDaysThreshold} روز)"
            );
            $results['downgraded']++;
            $this->logger->info('User level downgraded for inactivity', [
                'user_id' => $user->id,
                'from' => $user->level_slug,
                'monthly_days' => $user->monthly_active_days,
            ]);
        }

        return $results;
    }

    public function checkExpiredPurchases(): array
    {
        $results = ['checked' => 0, 'expired' => 0];

        $stmt = $this->db->prepare("
            SELECT id, user_id, level_slug 
            FROM user_level_purchases
            WHERE status = 'active' AND expires_at <= NOW()
        ");
        $stmt->execute();
        $expired = $stmt->fetchAll(\PDO::FETCH_OBJ);

        foreach ($expired as $purchase) {
            $results['checked']++;

            $stmtUp = $this->db->prepare("UPDATE user_level_purchases SET status = 'expired' WHERE id = ?");
            $stmtUp->execute([$purchase->id]);

            $eligible = null;
            if ($this->appSettings->get('level_activity_upgrade_enabled', 1)) {
                $totalScore = $this->scoreService->getScore('user', $purchase->user_id, 'score_' . ModuleContext::GLOBAL->value);
                $eligible = $this->levelModel->getEligibleLevel($totalScore);
            }

            $newLevel = $eligible ? $eligible->slug : 'bronze';

            $this->changeLevel(
                $purchase->user_id,
                $purchase->level_slug,
                $newLevel,
                'expire',
                'انقضای سطح خریداری‌شده'
            );

            $results['expired']++;
        }

        return $results;
    }

    public function monthlyReset(): int
    {
        $stmt = $this->db->prepare("UPDATE users SET monthly_active_days = 0 WHERE deleted_at IS NULL");
        $stmt->execute();
        $count = $stmt->rowCount();

        $this->logger->info('Monthly active days reset', ['affected' => $count]);
        return $count;
    }

    public function changeLevel(int $userId, ?string $fromSlug, string $toSlug, string $changeType, string $reason = ''): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\User\ChangeUserLevelJob::class);
        return $job->handle($userId, $fromSlug, $toSlug, $changeType, $reason);
    }

    public function adminChangeLevel(int $userId, string $newSlug, string $reason = ''): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\User\AdminChangeUserLevelJob::class);
        return $job->handle($userId, $newSlug, $reason);
    }

    public function getUserBonuses(int $userId): object
    {
        $stmt = $this->db->prepare("SELECT level_slug FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_OBJ);

        $defaults = (object) [
            'earning_bonus_percent' => 0,
            'referral_bonus_percent' => 0,
            'daily_task_limit_bonus' => 0,
            'withdrawal_limit_bonus' => 0,
            'priority_support' => 0,
            'special_badge' => 0,
        ];

        if (!$user) return $defaults;

        $level = $this->levelModel->findBySlug($user->level_slug);
        if (!$level) return $defaults;

        return (object) [
            'earning_bonus_percent' => (float) $level->earning_bonus_percent,
            'referral_bonus_percent' => (float) $level->referral_bonus_percent,
            'daily_task_limit_bonus' => (int) $level->daily_task_limit_bonus,
            'withdrawal_limit_bonus' => (float) $level->withdrawal_limit_bonus,
            'priority_support' => (bool) $level->priority_support,
            'special_badge' => (bool) $level->special_badge,
        ];
    }

    public function applyEarningBonus(int $userId, float $baseAmount): float
    {
        $bonuses = $this->getUserBonuses($userId);
        if ($bonuses->earning_bonus_percent <= 0) return $baseAmount;
        return \round($baseAmount * (1 + $bonuses->earning_bonus_percent / 100), 2);
    }

    public function getProgress(int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT level_slug, level_type, level_expires_at,
                   active_days_count, completed_tasks_count,
                   total_earning_irt, total_earning_usdt, monthly_active_days
            FROM users WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$user) return null;

        $currentLevel = $this->levelModel->findBySlug($user->level_slug);
        $nextLevel = $this->levelModel->getNextLevel($user->level_slug);

        if (!$nextLevel) {
            return (object) [
                'current' => $currentLevel,
                'next' => null,
                'is_max' => true,
                'progress' => 100,
                'details' => [],
            ];
        }

        $details = [];
        $progressValues = [];

        if ($nextLevel->min_active_days > 0) {
            $p = \min(100, \round(($user->active_days_count / $nextLevel->min_active_days) * 100));
            $details[] = (object) [
                'label' => 'روز فعالیت',
                'current' => (int) $user->active_days_count,
                'required' => (int) $nextLevel->min_active_days,
                'percent' => $p,
            ];
            $progressValues[] = $p;
        }

        if ($nextLevel->min_completed_tasks > 0) {
            $p = \min(100, \round(($user->completed_tasks_count / $nextLevel->min_completed_tasks) * 100));
            $details[] = (object) [
                'label' => 'تسک تکمیل‌شده',
                'current' => (int) $user->completed_tasks_count,
                'required' => (int) $nextLevel->min_completed_tasks,
                'percent' => $p,
            ];
            $progressValues[] = $p;
        }

        if ($nextLevel->min_total_earning > 0) {
            $p = \min(100, \round(($user->total_earning_irt / $nextLevel->min_total_earning) * 100));
            $details[] = (object) [
                'label' => 'درآمد کل (تومان)',
                'current' => (float) $user->total_earning_irt,
                'required' => (float) $nextLevel->min_total_earning,
                'percent' => $p,
            ];
            $progressValues[] = $p;
        }

        $avgProgress = !empty($progressValues) ? \round(\array_sum($progressValues) / \count($progressValues)) : 0;

        return (object) [
            'current' => $currentLevel,
            'next' => $nextLevel,
            'is_max' => false,
            'progress' => $avgProgress,
            'details' => $details,
            'monthly_active_days' => (int) $user->monthly_active_days,
            'level_type' => $user->level_type,
            'level_expires_at' => $user->level_expires_at,
        ];
    }

    private function isEnabled(): bool
    {
        return (bool) $this->appSettings->get('level_system_enabled', 1);
    }
}
