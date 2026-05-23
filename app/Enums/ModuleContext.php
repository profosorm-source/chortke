<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * دامنه‌ها و ماژول‌های سیستم برای تفکیک منطقی امتیازات و تعاملات
 */
enum ModuleContext: string
{
    case YOUTUBE_TASKS = 'youtube_tasks';
    case SOCIAL_TASKS = 'social_tasks';
    case CUSTOM_TASKS = 'custom_tasks';
    case GOOGLE_SEARCH_TASKS = 'google_search_tasks';
    case INVESTMENT = 'investment';
    case GLOBAL = 'global'; // برای امتیازات عمومی سیستم

    public function label(): string
    {
        return match($this) {
            self::YOUTUBE_TASKS => 'تسک‌های یوتیوب',
            self::SOCIAL_TASKS => 'تسک‌های شبکه‌های اجتماعی',
            self::CUSTOM_TASKS => 'تسک‌های سفارشی',
            self::GOOGLE_SEARCH_TASKS => 'تسک‌های جستجوی گوگل',
            self::INVESTMENT => 'سرمایه‌گذاری',
            self::GLOBAL => 'عمومی',
        };
    }
}
