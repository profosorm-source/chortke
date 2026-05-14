<?php

namespace App\Enums;

class TicketStatus
{
    const OPEN = 'open';
    const ANSWERED = 'answered';
    const IN_PROGRESS = 'in_progress';
    const ON_HOLD = 'on_hold';
    const CLOSED = 'closed';
    
    public static function all(): array
    {
        return [
            self::OPEN,
            self::ANSWERED,
            self::IN_PROGRESS,
            self::ON_HOLD,
            self::CLOSED
        ];
    }
    
    public static function label(string $status): string
    {
        $labels = [
            self::OPEN => 'باز',
            self::ANSWERED => 'پاسخ داده شده',
            self::IN_PROGRESS => 'در حال بررسی',
            self::ON_HOLD => 'در انتظار پاسخ کاربر',
            self::CLOSED => 'بسته شده'
        ];
        
        return $labels[$status] ?? 'نامشخص';
    }
    
    public static function badgeClass(string $status): string
    {
        $classes = [
            self::OPEN => 'badge-success',
            self::ANSWERED => 'badge-info',
            self::IN_PROGRESS => 'badge-warning',
            self::ON_HOLD => 'badge-secondary',
            self::CLOSED => 'badge-dark'
        ];
        
        return $classes[$status] ?? 'badge-secondary';
    }
}