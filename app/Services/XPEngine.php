<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Score;
use App\Models\UserVacation;
use Core\Database;

/**
 * XPEngine - موتور جامع محاسبه و توزیع امتیاز تجربه (XP) و سطوح چورتکه
 */
class XPEngine extends BaseService
{
    private Score $scoreModel;
    private UserVacation $vacationModel;

    public function __construct(Database $db, Score $scoreModel, UserVacation $vacationModel)
    {
        parent::__construct($db);
        $this->scoreModel = $scoreModel;
        $this->vacationModel = $vacationModel;
    }

    /**
     * تخصیص امتیاز تجربه به کاربر در یکی از ۴ ماژول اصلی همراه با اعمال ضریب هم‌افزایی
     * 
     * @param int $userId شناسه کاربر
     * @param string $module نام ماژول اصلی (youtube, custom_tasks, social_tasks, google_search)
     * @param string $activityType نوع رویداد انجام‌شده
     */
    public function awardXP(int $userId, string $module, string $activityType): bool
    {
        // ۱. تعیین میزان امتیاز پایه مصوب برای هر فعالیت
        $baseXp = match ($module) {
            'youtube' => 2.0,
            'custom_tasks' => 1.2,
            'social_tasks' => 1.2,
            'google_search' => 1.1,
            default => 0.0,
        };

        if ($baseXp === 0.0) {
            return false;
        }

        // ۲. ثبت امتیاز در تخصص ماژولار (لایه اول - دامنه‌های مستقل)
        $this->scoreModel->addEvent([
            'entity_type' => 'user',
            'entity_id' => $userId,
            'domain' => 'xp_' . $module,
            'delta' => $baseXp,
            'source' => $activityType,
            'meta' => [
                'base_xp' => $baseXp,
                'module' => $module
            ]
        ]);

        // ۳. محاسبه ضریب هم‌افزایی روزانه (Synergy Multiplier)
        // بررسی تعداد ماژول‌های متمایزی که کاربر امروز در آن‌ها فعالیت کرده است
        $multiplier = $this->calculateDailySynergyMultiplier($userId);

        // ۴. ثبت امتیاز تجربه عمومی (Global XP) با اعمال ضریب هم‌افزایی
        $finalGlobalXp = $baseXp * $multiplier;

        return $this->scoreModel->addEvent([
            'entity_type' => 'user',
            'entity_id' => $userId,
            'domain' => 'xp_global',
            'delta' => $finalGlobalXp,
            'source' => $activityType,
            'meta' => [
                'base_xp' => $baseXp,
                'synergy_multiplier' => $multiplier,
                'final_global_xp' => $finalGlobalXp,
                'module' => $module
            ]
        ]);
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

        return match ($activeDomains) {
            1 => 1.0,
            2 => 1.10, // ۱۰٪ بونوس برای ۲ ماژول مختلف در روز
            3 => 1.25, // ۲۵٪ بونوس برای ۳ ماژول مختلف در روز
            4 => 1.40, // ۴۰٪ بونوس ویژه فعالیت در هر ۴ ماژول اصلی!
            default => 1.40,
        };
    }

    /**
     * دریافت خلاصه وضعیت لول و امتیازهای کاربر
     */
    public function getUserXPSummary(int $userId): array
    {
        $globalXp = $this->scoreModel->getDomainScore($userId, 'xp_global');

        // فرمول نمایی پیشرفت متعادل مصوب: Required XP = 100 * (Level ^ 1.8)
        // معادل ریاضی سطح: Level = (GlobalXP / 100) ^ (1 / 1.8)
        $globalLevel = 1;
        if ($globalXp > 100) {
            $globalLevel = (int)\floor(\pow(($globalXp / 100), (1 / 1.8)));
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
