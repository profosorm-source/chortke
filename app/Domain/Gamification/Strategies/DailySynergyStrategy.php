<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Strategies;

use App\Contracts\GamificationStrategyInterface;
use App\Enums\ModuleContext;
use App\Enums\MetricType;
use App\Models\User;

/**
 * استراتژی محاسبه ضریب هم‌افزایی (Synergy Multiplier) بر اساس فعالیت در ماژول‌های مختلف
 */
class DailySynergyStrategy implements GamificationStrategyInterface
{
    public function calculate(User $user, ModuleContext $context, array $payload = []): float|int
    {
        $activeDomainsCount = (int)($payload['active_domains_count'] ?? 1);
        $yesterdayMultiplier = (float)($payload['yesterday_multiplier'] ?? 1.0);
        
        $activeDomainsCount = max(1, $activeDomainsCount);

        // ضرایب بر اساس تعداد ماژول‌های فعال امروز کاربر
        $rawMultiplier = match($activeDomainsCount) {
            1 => 1.0,
            2 => 1.10, // ۱۰ درصد رشد به دلیل فعالیت در دو بخش
            3 => 1.25, // ۲۵ درصد رشد
            default => 1.40, // ۴۰ درصد رشد برای فعالین حرفه‌ای
        };

        // اعمال محدودیت امنیتی: سقف افزایش روزانه نهایتا ۱۵ درصد بیشتر از روز قبل باشد
        $maxAllowed = $yesterdayMultiplier + 0.15;
        
        return min($rawMultiplier, $maxAllowed);
    }

    public function getMetricType(): MetricType
    {
        return MetricType::XP;
    }
}
