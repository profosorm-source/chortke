<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * انواع متریک‌ها و امتیازات کاربران
 */
enum MetricType: string
{
    case XP = 'xp';       // برای ارتقای لول
    case TRUST = 'trust'; // برای اعتبار کاربر در تسک/سرمایه‌گذاری
    case SCORE = 'score'; // امتیاز عمومی برای رتبه‌بندی/پاداش
}
