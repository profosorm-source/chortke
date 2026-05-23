<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * انواع تعاملاتی که یک کاربر با یک محتوا می‌تواند داشته باشد
 */
enum InteractionType: string
{
    case FAVORITE = 'favorite';
    case RATING = 'rating';
    case REPORT = 'report';
    case COMMENT = 'comment'; // اگر کامنت‌ها هم پلیمورفیک شدند
}
