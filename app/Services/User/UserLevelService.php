<?php
declare(strict_types=1);

namespace App\Services\User;

use App\Models\UserLevel;
use App\Models\UserLevelHistory;
use App\Models\User;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;
use App\Services\WalletService;
use App\Services\Shared\ReferralService as ReferralCommissionService;
use Core\EventDispatcher;

class UserLevelService extends \App\Services\BaseService
{
    private WalletService $walletService;
    private ReferralCommissionService $commissionService;
    private UserLevel $levelModel;
    private UserLevelHistory $historyModel;
    private Database $db;
    private SettingService $settingService;
    private EventDispatcher $eventDispatcher;

    public function __construct(
        Database $db,
        WalletService $walletService,
        ReferralCommissionService $commissionService,
        UserLevel $levelModel,
        UserLevelHistory $historyModel,
        SettingService $settingService,
        EventDispatcher $eventDispatcher,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->levelModel = $levelModel;
        $this->historyModel = $historyModel;
        $this->db = $db;
        $this->walletService = $walletService;
        $this->commissionService = $commissionService;
        $this->settingService = $settingService;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function recordDailyActivity(int $userId): void
    {
        if (!$this->isEnabled()) return;

        $today = \date('Y-m-d');
        $currentMonth = \date('Y-m');

        $this->db->beginTransaction();
        try {
            // MED-04: Wrap read-modify-write steps inside explicit transaction boundaries with FOR UPDATE to stop double counting
            $stmt = $this->db->prepare("SELECT last_active_date, monthly_active_days, active_days_count FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$user) {
                $this->db->commit();
                return;
            }

            // If already registered today, rollback or commit and skip
            if ($user->last_active_date === $today) {
                $this->db->commit();
                return;
            }

            $lastMonth = $user->last_active_date ? \substr($user->last_active_date, 0, 7) : null;
            $monthlyDays = ($lastMonth === $currentMonth) ? (int)$user->monthly_active_days + 1 : 1;

            $stmt = $this->db->prepare("
                UPDATE users SET 
                    last_active_date = ?,
                    active_days_count = active_days_count + 1,
                    monthly_active_days = ?
                WHERE id = ?
            ");
            $stmt->execute([$today, $monthlyDays, $userId]);
            
            $this->db->commit();

            // Verify user potential upgrade levels
            if ($this->settingService->get('level_activity_upgrade_enabled', 1)) {
                $this->checkUpgrade($userId);
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('level.record_daily_activity.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function recordTaskCompletion(int $userId, float $earnedAmount, string $currency = 'irt'): void
    {
        if (!$this->isEnabled()) return;

        // HIGH-04: Explicitly branch SQL statements into static strings to shield the app from dynamic field injections
        if (\strtolower($currency) === 'usdt') {
            $stmt = $this->db->prepare("
                UPDATE users SET 
                    completed_tasks_count = completed_tasks_count + 1,
                    total_earning_usdt = total_earning_usdt + ?
                WHERE id = ?
            ");
        } else {
            $stmt = $this->db->prepare("
                UPDATE users SET 
                    completed_tasks_count = completed_tasks_count + 1,
                    total_earning_irt = total_earning_irt + ?
                WHERE id = ?
            ");
        }

        $stmt->execute([$earnedAmount, $userId]);

        $this->recordDailyActivity($userId);
    }

    /**
     * بررسی و ارتقای سطح بر اساس فعالیت
     */
    public function checkUpgrade(int $userId): ?string
    {
        $this->db->beginTransaction();

        try {
            // قفل کردن سطر کاربر برای جلوگیری از Race Condition در ارتقای همزمان (Issue #24)
            $stmt = $this->db->prepare("
                SELECT level_slug, level_type, level_expires_at, active_days_count, 
                       completed_tasks_count, total_earning_irt, total_earning_usdt
                FROM users 
                WHERE id = ? AND deleted_at IS NULL
                FOR UPDATE
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(\PDO::FETCH_OBJ);

            if (!$user) {
                $this->db->rollBack();
                return null;
            }

            // اگر سطح خریداری شده و هنوز معتبر است، ارتقا با فعالیت اعمال نمی‌شود
            if ($user->level_type === 'purchased') {
                if ($user->level_expires_at && \strtotime($user->level_expires_at) > \time()) {
                    $this->db->rollBack();
                    return null; // سطح خریداری‌شده هنوز معتبر
                }
            }

            $eligible = $this->levelModel->getEligibleLevel(
                (int) $user->active_days_count,
                (int) $user->completed_tasks_count,
                (float) $user->total_earning_irt,
                (float) $user->total_earning_usdt
            );

            if (!$eligible) {
                $this->db->rollBack();
                return null;
            }

            $currentLevel = $this->levelModel->findBySlug($user->level_slug);
            if (!$currentLevel) {
                $this->db->rollBack();
                return null;
            }

            // فقط ارتقا (نه سقوط)
            if ($eligible->sort_order <= $currentLevel->sort_order) {
                $this->db->rollBack();
                return null;
            }

            // ارتقا
            $this->changeLevel($userId, $user->level_slug, $eligible->slug, 'upgrade', 'ارتقا بر اساس فعالیت');

            $this->db->commit();

            $this->logger->info('User level upgraded by activity', [
                'user_id' => $userId,
                'from' => $user->level_slug,
                'to' => $eligible->slug,
            ]);

            return $eligible->slug;

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('level.upgrade_check.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * خرید سطح
     */
    public function purchaseLevel(int $userId, string $levelSlug, string $currency = 'irt'): array
    {
        if (!$this->isEnabled() || !$this->settingService->get('level_purchase_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم خرید سطح غیرفعال است.'];
        }

        $level = $this->levelModel->findBySlug($levelSlug);
        if (!$level || !$level->is_active) {
            return ['success' => false, 'message' => 'سطح مورد نظر یافت نشد.'];
        }

        $price = $currency === 'usdt' ? (float) $level->purchase_price_usdt : (float) $level->purchase_price_irt;
        if ($price <= 0) {
            return ['success' => false, 'message' => 'این سطح قابل خرید نیست.'];
        }

        // Ensure safe idempotency token and boundary check to block concurrent duplicate execution rolls
        // Ensure safe idempotency token - include hour to allow retry later in day if previous expired
        $idempotencyKey = "level_purch_{$userId}_{$levelSlug}_" . \date('YmdH');

        // MED-05: Enforce strong validation overlap: Block duplicate purchases of levels that haven't expired yet
        $stmt = $this->db->prepare("SELECT level_slug, level_expires_at, level_type FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $u = $stmt->fetch(\PDO::FETCH_OBJ);

        if ($u && $u->level_slug === $levelSlug && $u->level_type === 'purchased') {
            if ($u->level_expires_at && \strtotime($u->level_expires_at) > \time()) {
                return ['success' => false, 'message' => 'شما در حال حاضر اشتراک فعال برای این سطح را دارا هستید.'];
            }
        }

        $stmt = $this->db->prepare("SELECT id FROM user_level_purchases WHERE idempotency_key = ?");
        $stmt->execute([$idempotencyKey]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'شما امروز درخواست مشابهی برای ارتقای این سطح ثبت کرده‌اید.'];
        }

        try {
            $this->db->beginTransaction();

            // 🛡️ MED-12 Fix (CRITICAL): Implement atomic transaction-level locking
            // Lock user wallet with FOR UPDATE to prevent race conditions during purchase
            $stmt = $this->db->prepare("SELECT balance_irt, balance_usdt FROM wallets WHERE user_id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(\PDO::FETCH_OBJ);
            
            if (!$wallet) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کیف پول کاربر یافت نشد.'];
            }

            // Check balance before withdrawal to prevent TOCTOU
            $balanceField = ($currency === 'usdt') ? 'balance_usdt' : 'balance_irt';
            $currentBalance = (float)$wallet->$balanceField;
            
            if (bccomp((string)$currentBalance, (string)$price, 2) < 0) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست.'];
            }

            // Use withdrawInTransaction() for atomic transaction-based withdrawal
            $withdrawResult = $this->walletService->withdrawInTransaction(
                $userId,
                $price,
                $currency,
                [
                    'type' => 'vip_purchase',
                    'description' => "خرید سطح {$level->name}",
                    'ref_type' => 'user_level_purchase',
                    'ref_id' => null,
                ]
            );

            if (empty($withdrawResult['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $withdrawResult['message'] ?? 'موجودی کافی نیست.'];
            }

            $txId = $withdrawResult['transaction_id'] ?? null;

            // تاریخ انقضا
            $duration = (int) $level->purchase_duration_days;
            $expiresAt = \date('Y-m-d H:i:s', \strtotime("+{$duration} days"));

            // ثبت خرید
            $stmt = $this->db->prepare("
                INSERT INTO user_level_purchases 
                (user_id, level_slug, amount, currency, duration_days, starts_at, expires_at, status, transaction_id, idempotency_key)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, 'active', ?, ?)
            ");
            $stmt->execute([$userId, $levelSlug, $price, $currency, $duration, $expiresAt, $txId, $idempotencyKey]);

            // بروزرسانی سطح کاربر
            $stmt = $this->db->prepare("SELECT level_slug FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $currentUser = $stmt->fetch(\PDO::FETCH_OBJ);
            $fromLevel = $currentUser->level_slug ?? 'bronze';

            $stmt = $this->db->prepare("
                UPDATE users SET 
                    level_slug = ?, 
                    level_type = 'purchased', 
                    level_expires_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$levelSlug, $expiresAt, $userId]);

            // ثبت تاریخچه
            $this->historyModel->create([
                'user_id' => $userId,
                'from_level' => $fromLevel,
                'to_level' => $levelSlug,
                'change_type' => 'purchase',
                'reason' => "خرید سطح {$level->name} به مدت {$duration} روز",
                'metadata' => ['price' => $price, 'currency' => $currency, 'duration' => $duration],
            ]);

            // کمیسیون معرفی
            $this->commissionService->processCommission($userId, 'vip_purchase', null, $price, $currency);

            $this->db->commit();

            $this->logger->info('User level purchased', [
                'user_id' => $userId,
                'level' => $levelSlug,
                'price' => $price,
                'currency' => $currency,
            ]);

            return [
                'success' => true,
                'message' => "سطح «{$level->name}» با موفقیت خریداری شد.",
                'level' => $level,
                'expires_at' => $expiresAt,
            ];

        } catch (\Exception $e) {
    $this->db->rollBack();
    $this->logger->error('level.purchase.failed', [
        'channel' => 'level',
        'user_id' => $userId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return ['success' => false, 'message' => 'خطا در خرید سطح. لطفاً دوباره تلاش کنید.'];
}
    }

    /**
     * بررسی سقوط سطح (CronJob — روزانه)
     */
    public function checkDowngrades(): array
    {
        if (!$this->isEnabled()) return ['checked' => 0, 'downgraded' => 0];

        $inactiveDaysThreshold = (int) $this->settingService->get('level_downgrade_inactive_days', 3);
        $currentMonth = \date('Y-m');
        $daysInMonth = (int) \date('t');
        $today = (int) \date('j');

        // فقط در روزهای آخر ماه بررسی شود (بعد از روز 25)
        if ($today < 25) {
            return ['checked' => 0, 'downgraded' => 0, 'reason' => 'too_early'];
        }

        $results = ['checked' => 0, 'downgraded' => 0];

        // کاربرانی که سطحشان activity است و ماهانه کمتر از حد فعالیت داشته‌اند
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

    /**
     * بررسی انقضای سطح خریداری‌شده (CronJob — روزانه)
     */
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

            // بروزرسانی خرید
            $stmtUp = $this->db->prepare("UPDATE user_level_purchases SET status = 'expired' WHERE id = ?");
            $stmtUp->execute([$purchase->id]);

            // بررسی آیا کاربر سطح فعالیتی بالاتری دارد
            $stmtUser = $this->db->prepare("
                SELECT active_days_count, completed_tasks_count, total_earning_irt, total_earning_usdt
                FROM users WHERE id = ?
            ");
            $stmtUser->execute([$purchase->user_id]);
            $userData = $stmtUser->fetch(\PDO::FETCH_OBJ);

            $eligible = null;
            if ($userData && $this->settingService->get('level_activity_upgrade_enabled', 1)) {
                $eligible = $this->levelModel->getEligibleLevel(
                    (int) $userData->active_days_count,
                    (int) $userData->completed_tasks_count,
                    (float) $userData->total_earning_irt,
                    (float) $userData->total_earning_usdt
                );
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

    /**
     * ریست ماهانه شمارنده‌ها (CronJob — اول هر ماه)
     */
    public function monthlyReset(): int
    {
        $stmt = $this->db->prepare("UPDATE users SET monthly_active_days = 0 WHERE deleted_at IS NULL");
        $stmt->execute();
        $count = $stmt->rowCount();

        $this->logger->info('Monthly active days reset', ['affected' => $count]);
        return $count;
    }

    /**
     * تغییر سطح کاربر
     */
    public function changeLevel(int $userId, ?string $fromSlug, string $toSlug, string $changeType, string $reason = ''): bool
    {
        $stmt = $this->db->prepare("
            UPDATE users SET 
                level_slug = ?, 
                level_type = CASE WHEN ? = 'purchase' THEN 'purchased' ELSE 'activity' END,
                level_expires_at = CASE WHEN ? IN ('downgrade','expire','reset') THEN NULL ELSE level_expires_at END,
                level_downgraded_at = CASE WHEN ? = 'downgrade' THEN NOW() ELSE level_downgraded_at END
            WHERE id = ?
        ");
        $stmt->execute([$toSlug, $changeType, $changeType, $changeType, $userId]);

        $this->historyModel->create([
            'user_id' => $userId,
            'from_level' => $fromSlug,
            'to_level' => $toSlug,
            'change_type' => $changeType,
            'reason' => $reason,
        ]);

        // 📢 شلیک رویداد ارتقا فقط در صورتی که ارتقای واقعی، ادمین یا خرید باشد
        if (in_array($changeType, ['upgrade', 'purchase', 'admin'])) {
            try {
                $this->eventDispatcher->dispatch('level.upgraded', new \App\Events\LevelUpgradedEvent(
                    $userId,
                    $fromSlug ?? 'none',
                    $toSlug,
                    $changeType . ': ' . $reason
                ));
            } catch (\Throwable $e) {
                $this->logger->error('level.upgrade_event.failed', ['error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /**
     * تغییر سطح توسط ادمین
     */
    public function adminChangeLevel(int $userId, string $newSlug, string $reason = ''): bool
    {
        $stmt = $this->db->prepare("SELECT level_slug FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_OBJ);
        if (!$user) return false;

        $reason = htmlspecialchars(trim($reason), ENT_QUOTES, 'UTF-8');
        return $this->changeLevel($userId, $user->level_slug, $newSlug, 'admin', $reason);
    }

    /**
     * دریافت پاداش‌های سطح فعلی کاربر
     */
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

    /**
     * محاسبه درآمد با پاداش سطح
     */
    public function applyEarningBonus(int $userId, float $baseAmount): float
    {
        $bonuses = $this->getUserBonuses($userId);
        if ($bonuses->earning_bonus_percent <= 0) return $baseAmount;
        return \round($baseAmount * (1 + $bonuses->earning_bonus_percent / 100), 2);
    }

    /**
     * پیشرفت کاربر تا سطح بعدی
     */
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
        return (bool) $this->settingService->get('level_system_enabled', 1);
    }
}
