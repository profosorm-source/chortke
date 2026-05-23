<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Strategies;

use App\Contracts\GamificationStrategyInterface;
use App\Enums\ModuleContext;
use App\Enums\MetricType;
use App\Models\User;

/**
 * استراتژی محاسبه ریزش امتیاز (XP) به دلیل عدم فعالیت کاربر
 */
class InactivityDecayStrategy implements GamificationStrategyInterface
{
    public function calculate(User $user, ModuleContext $context, array $payload = []): float|int
    {
        $inactiveDays = (int)($payload['inactive_days'] ?? 0);
        $currentScore = (float)($payload['current_score'] ?? 0.0);
        $isVip = (bool)($payload['is_vip'] ?? false);

        // اگر کاربر فعال بوده یا امتیازی برای کسر ندارد
        if ($inactiveDays < 1 || $currentScore <= 0) {
            return 0.0;
        }

        $decayPercent = 0.0;

        if ($isVip) {
            // قانون کاربران ویژه (پولی): روز اول ریزش سنگین، روزهای بعد ریزش ثابت
            $decayPercent = ($inactiveDays === 1) ? 0.50 : 0.10;
        } else {
            // قانون کاربران عادی: ریزش پلکانی نزولی
            $decayPercent = match($inactiveDays) {
                1 => 0.20,
                2 => 0.15,
                3 => 0.10,
                default => 0.05,
            };
        }

        // مقدار خروجی همیشه منفی است چون مربوط به ریزش (Decay) است
        return -($currentScore * $decayPercent);
    }

    public function getMetricType(): MetricType
    {
        return MetricType::XP;
    }
}
