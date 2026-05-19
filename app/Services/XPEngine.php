<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Score;
use App\Models\UserVacation;
use Core\Database;
use App\Services\SettingService;

use App\Contracts\LoggerInterface;

/**
 * XPEngine - موتور جامع محاسبه و توزیع امتیاز تجربه (XP) و سطوح چورتکه
 */
class XPEngine extends BaseService
{
    private Database $db;
    private Score $scoreModel;
    private UserVacation $vacationModel;
    private SettingService $settingService;

    public function __construct(Database $db, Score $scoreModel, UserVacation $vacationModel, LoggerInterface $logger, SettingService $settingService)
    {
        parent::__construct($logger);
        $this->db = $db;
        $this->scoreModel = $scoreModel;
        $this->vacationModel = $vacationModel;
        $this->settingService = $settingService;
    }

    /**
     * تخصیص امتیاز تجربه به کاربر در یکی از ۴ ماژول اصلی همراه با اعمال ضریب هم‌افزایی
     * 
     * @param int $userId شناسه کاربر
     * @param string $module نام ماژول اصلی (youtube, custom_tasks, social_tasks, google_search)
     * @param string $activityType نوع رویداد انجام‌شده
     */
    public function awardXP(int $userId, string $module, string $activityType, ?int $taskExecutionId = null): bool
    {
        // ۱. تعیین میزان امتیاز پایه مصوب برای هر فعالیت
        $baseXpMap = $this->settingService->get('xp_engine_base_xp', [
            'youtube' => 2.0,
            'custom_tasks' => 1.2,
            'social_tasks' => 1.2,
            'google_search' => 1.1,
        ]);
        $baseXp = (float)($baseXpMap[$module] ?? 0.0);

        if ($baseXp === 0.0) {
            return false;
        }

        // Acquire MySQL/MariaDB advisory lock to guarantee idempotency in concurrent execution
        $lockSuffix = $taskExecutionId ? "exec_{$taskExecutionId}" : date('YmdH');
        $lockName = "xp_lock_{$userId}_{$module}_{$activityType}_{$lockSuffix}";
        $stmtLock = $this->db->prepare("SELECT GET_LOCK(?, 10)");
        $stmtLock->execute([$lockName]);
        $lockAcquired = (int)$stmtLock->fetchColumn();

        if (!$lockAcquired) {
            $this->logger->warning('xp_engine.award_xp.lock_failed', [
                'user_id' => $userId,
                'module' => $module,
                'activity' => $activityType
            ]);
            return false;
        }

        try {
            // ۱.۵. Idempotency Check
            $idempotencyKey = null;
            if ($taskExecutionId) {
                // Check if a score event was already awarded for this execution
                $existing = $this->db->prepare("
                    SELECT id FROM score_events 
                    WHERE entity_id = ? AND domain = ? AND source = ? 
                    AND (
                        JSON_EXTRACT(meta_json, '$.task_execution_id') = ?
                        OR meta_json LIKE ?
                    )
                    LIMIT 1
                ");
                $existing->execute([
                    $userId,
                    'xp_' . $module,
                    $activityType,
                    $taskExecutionId,
                    '%"task_execution_id":' . $taskExecutionId . '%'
                ]);
            } else {
                $idempotencyKey = hash('sha256', "{$userId}:{$module}:{$activityType}:" . date('Y-m-d-H'));
                $existing = $this->db->prepare("
                    SELECT id FROM score_events 
                    WHERE entity_id = ? AND domain = ? AND source = ? 
                    AND meta_json LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) 
                    LIMIT 1
                ");
                $existing->execute([$userId, 'xp_' . $module, $activityType, "%{$idempotencyKey}%"]);
            }

            if ($existing->fetch()) {
                $this->logger->warning('xp_engine.award_xp.duplicate_ignored', [
                    'user_id' => $userId,
                    'module' => $module,
                    'activity' => $activityType
                ]);
                return false;
            }

            $metaData = [
                'base_xp' => $baseXp,
                'module' => $module,
            ];
            if ($taskExecutionId) {
                $metaData['task_execution_id'] = $taskExecutionId;
            } else {
                $metaData['idempotency_key'] = $idempotencyKey;
            }

            // ۲. ثبت امتیاز در تخصص ماژولار (لایه اول - دامنه‌های مستقل)
            $this->scoreModel->addEvent([
                'entity_type' => 'user',
                'entity_id' => $userId,
                'domain' => 'xp_' . $module,
                'delta' => $baseXp,
                'source' => $activityType,
                'meta' => $metaData
            ]);

            // ۳. محاسبه ضریب هم‌افزایی روزانه (Synergy Multiplier)
            // بررسی تعداد ماژول‌های متمایزی که کاربر امروز در آن‌ها فعالیت کرده است
            $multiplier = $this->calculateDailySynergyMultiplier($userId);

            // ۴. ثبت امتیاز تجربه عمومی (Global XP) با اعمال ضریب هم‌افزایی
            $finalGlobalXp = $baseXp * $multiplier;

            $globalMeta = [
                'base_xp' => $baseXp,
                'synergy_multiplier' => $multiplier,
                'final_global_xp' => $finalGlobalXp,
                'module' => $module
            ];
            if ($taskExecutionId) {
                $globalMeta['task_execution_id'] = $taskExecutionId;
            }

            return $this->scoreModel->addEvent([
                'entity_type' => 'user',
                'entity_id' => $userId,
                'domain' => 'xp_global',
                'delta' => $finalGlobalXp,
                'source' => $activityType,
                'meta' => $globalMeta
            ]);
        } finally {
            $stmtRelease = $this->db->prepare("SELECT RELEASE_LOCK(?)");
            $stmtRelease->execute([$lockName]);
        }
    }

    /**
     * محاسبه ضریب هم‌افزایی روزانه بر اساس دامنه‌های فعال امروز کاربر
     */
    public function calculateDailySynergyMultiplier(int $userId): float
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT domain) as active_domains
            FROM score_events
            WHERE entity_id = ? AND entity_type = 'user'
            AND domain LIKE 'xp_%' AND domain != 'xp_global'
            AND DATE(created_at) = CURRENT_DATE()
        ");
        $stmt->execute([$userId]);
        $activeDomains = (int)$stmt->fetchColumn();

        // تضمین حداقل ۱ دامین برای فعالیت فعلی
        $activeDomains = \max(1, $activeDomains);

        $multiplierMap = $this->settingService->get('xp_engine_synergy_multipliers', [
            1 => 1.0,
            2 => 1.10,
            3 => 1.25,
            4 => 1.40,
        ]);

        return (float)($multiplierMap[$activeDomains] ?? $multiplierMap[max(array_keys($multiplierMap))] ?? 1.40);
    }

    /**
     * دریافت خلاصه وضعیت لول و امتیازهای کاربر
     */
    public function getUserXPSummary(int $userId): array
    {
        $globalXp = $this->scoreModel->getDomainScore($userId, 'xp_global');

        // فرمول نمایی پیشرفت متعادل مصوب
        $divisor = (float)$this->settingService->get('xp_level_divisor', 100.0);
        $exponent = (float)$this->settingService->get('xp_level_exponent', 1.8);
        
        $globalLevel = 1;
        if ($globalXp > $divisor) {
            $globalLevel = (int)\floor(\pow(($globalXp / $divisor), (1 / $exponent)));
        }
        $globalLevel = \max(1, $globalLevel);

        return [
            'global_xp' => $globalXp,
            'global_level' => $globalLevel,
            'isOnVacation' => $this->vacationModel->isUserOnVacation($userId),
            'specialties' => [
                'youtube' => $this->scoreModel->getDomainScore($userId, 'xp_youtube'),
                'custom_tasks' => $this->scoreModel->getDomainScore($userId, 'xp_custom_tasks'),
                'social_tasks' => $this->scoreModel->getDomainScore($userId, 'xp_social_tasks'),
                'google_search' => $this->scoreModel->getDomainScore($userId, 'xp_google_search'),
            ]
        ];
    }
}
