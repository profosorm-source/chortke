<?php

declare(strict_types=1);

namespace App\Domain\Gamification\Strategies;

use App\Contracts\GamificationStrategyInterface;
use App\Enums\ModuleContext;
use App\Enums\MetricType;
use App\Models\User;

/**
 * استراتژی محاسبه پنالتی‌ها و تغییرات Trust (میزان اعتبار/صحت کاربر)
 */
class TrustEvaluationStrategy implements GamificationStrategyInterface
{
    public function calculate(User $user, ModuleContext $context, array $payload = []): float|int
    {
        $action = (string)($payload['action'] ?? 'minor_violation');
        
        return match($action) {
            // پنالتی‌ها (کاهش Trust)
            'critical_violation' => -50.0, // مثل تقلب اثبات شده یا اسپم سنگین
            'task_rejected' => -10.0,      // رد شدن تسک توسط کارفرما
            'minor_violation' => -5.0,     // خطاهای کوچک
            'manual_adjustment' => (float)($payload['delta'] ?? 0.0), // تنظیم دستی مدیر
            
            // پاداش‌ها (افزایش Trust)
            'task_approved' => +2.0,       // تایید شدن صحیح تسک
            'investment_success' => +5.0,  // اتمام موفق سرمایه‌گذاری
            
            default => 0.0,
        };
    }

    public function getMetricType(): MetricType
    {
        return MetricType::TRUST; // تغییرات فقط روی پارامتر Trust انجام می‌شود
    }
}
