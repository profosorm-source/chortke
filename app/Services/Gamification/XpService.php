<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\User;
use App\Models\Score;
use App\Models\UserVacation;
use App\Enums\ModuleContext;
use App\Contracts\LoggerInterface;
use App\Domain\Gamification\Strategies\DailySynergyStrategy;
use App\Domain\Gamification\Strategies\InactivityDecayStrategy;
use Core\Database;
use Core\Cache;

/**
 * سرویس مدیریت تجربه (XP)
 * مسئولیت: اعطای XP، محاسبه هم‌افزایی روزانه و اعمال ریزش (Decay)
 */
class XpService
{
    private \Core\Cache $cache;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private Score $scoreModel;
    private UserVacation $vacationModel;
    private DailySynergyStrategy $synergyStrategy;
    private InactivityDecayStrategy $decayStrategy;
    public function __construct(
        \Core\Cache $cache,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        Score $scoreModel,
        UserVacation $vacationModel,
        DailySynergyStrategy $synergyStrategy,
        InactivityDecayStrategy $decayStrategy
    ) {        $this->cache = $cache;
        $this->db = $db;
        $this->logger = $logger;
        $this->scoreModel = $scoreModel;
        $this->vacationModel = $vacationModel;
        $this->synergyStrategy = $synergyStrategy;
        $this->decayStrategy = $decayStrategy;

        
    }

    /**
     * اعطای XP به کاربر در یک ماژول مشخص با تضمین Idempotency و قفل همزمانی
     */
    public function award(int $userId, ModuleContext $context, float $baseXp, ?string $idempotencyKey = null): bool
    {
        if ($baseXp <= 0) return false;

        $lockName = "xp_lock_{$userId}_{$context->value}_{$idempotencyKey}";
        $stmtLock = $this->db->prepare("SELECT GET_LOCK(?, 10)");
        $stmtLock->execute([$lockName]);
        $lockAcquired = (int)$stmtLock->fetchColumn();

        if (!$lockAcquired) {
            $this->logger->warning('xp_service.award_xp.lock_failed', [
                'user_id' => $userId,
                'context' => $context->value
            ]);
            return false;
        }

        try {
            $this->db->beginTransaction();

            // بررسی Idempotency برای جلوگیری از اعطای تکراری
            if ($idempotencyKey) {
                $existing = $this->db->prepare("
                    SELECT id FROM score_events 
                    WHERE entity_id = ? AND domain = ? AND source = 'activity' 
                    AND JSON_EXTRACT(meta_json, '$.idempotency_key') = ?
                    LIMIT 1
                ");
                $existing->execute([$userId, 'xp_' . $context->value, $idempotencyKey]);
                
                if ($existing->fetch()) {
                    $this->db->rollBack();
                    return false; // قبلا ثبت شده است
                }
            }

            // ۱. ثبت XP در ماژول مربوطه
            $this->scoreModel->addEvent([
                'entity_type' => 'user',
                'entity_id' => $userId,
                'domain' => 'xp_' . $context->value,
                'delta' => $baseXp,
                'source' => 'activity',
                'meta' => ['idempotency_key' => $idempotencyKey]
            ]);

            // ۲. محاسبه ضریب هم‌افزایی روزانه (Synergy)
            $activeDomainsCount = $this->getActiveDomainsCountToday($userId);
            $yesterdayMultiplier = (float)($this->cache->get("synergy:{$userId}:" . \date('Y-m-d', \strtotime('-1 day'))) ?? 1.0);
            
            $multiplier = $this->synergyStrategy->calculate($userId, $context, [
                'active_domains_count' => $activeDomainsCount,
                'yesterday_multiplier' => $yesterdayMultiplier
            ]);

            // ۳. ثبت XP عمومی (Global XP) برای ارتقای لول کاربر
            $finalGlobalXp = $baseXp * $multiplier;
            $this->scoreModel->addEvent([
                'entity_type' => 'user',
                'entity_id' => $userId,
                'domain' => 'xp_' . ModuleContext::GLOBAL->value,
                'delta' => $finalGlobalXp,
                'source' => 'synergy_activity',
                'meta' => ['multiplier' => $multiplier, 'context' => $context->value]
            ]);

            $this->cache->set("synergy:{$user->id}:" . \date('Y-m-d'), $multiplier, 86400);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('xp_service.award_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return false;
        } finally {
            $stmtRelease = $this->db->prepare("SELECT RELEASE_LOCK(?)");
            $stmtRelease->execute([$lockName]);
        }
    }

    /**
     * اعمال ریزش عدم فعالیت (Decay) روی تمام دامنه‌های فعال
     */
    public function applyDecay(User $user, int $inactiveDays): void
    {
        // چک کردن وضعیت مرخصی (Vacation Mode)
        if ($this->vacationModel->isUserOnVacation($user->id)) {
            return;
        }

        $isVip = in_array($user->level_slug, ['silver', 'gold', 'vip', 'platinum']);

        $contexts = [
            ModuleContext::YOUTUBE_TASKS,
            ModuleContext::SOCIAL_TASKS,
            ModuleContext::CUSTOM_TASKS,
            ModuleContext::GOOGLE_SEARCH_TASKS
        ];

        foreach ($contexts as $context) {
            $currentScore = $this->scoreModel->getDomainScore($user->id, 'xp_' . $context->value);

            $penalty = $this->decayStrategy->calculate($user, $context, [
                'inactive_days' => $inactiveDays,
                'current_score' => $currentScore,
                'is_vip' => $isVip
            ]);

            if ($penalty < 0) {
                $this->scoreModel->addEvent([
                    'entity_type' => 'user',
                    'entity_id' => $user->id,
                    'domain' => 'xp_' . $context->value,
                    'delta' => $penalty,
                    'source' => 'inactivity_decay',
                    'meta' => ['inactive_days' => $inactiveDays]
                ]);
            }
        }
    }

    private function getActiveDomainsCountToday(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT domain) FROM score_events
            WHERE entity_id = ? AND entity_type = 'user'
            AND domain LIKE 'xp_%' AND domain != 'xp_global'
            AND DATE(created_at) = CURRENT_DATE()
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() ?: 1;
    }
}
