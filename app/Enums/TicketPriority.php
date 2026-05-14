<?php

namespace App\Enums;

class TicketPriority
{
    const LOW = 'low';
    const NORMAL = 'normal';
    const HIGH = 'high';
    const URGENT = 'urgent';
    
    public static function all(): array
    {
        return [
            self::LOW,
            self::NORMAL,
            self::HIGH,
            self::URGENT
        ];
    }
    
    public static function label(string $priority): string
    {
        $labels = [
            self::LOW => 'پایین',
            self::NORMAL => 'عادی',
            self::HIGH => 'بالا',
            self::URGENT => 'فوری'
        ];
        
        return $labels[$priority] ?? 'عادی';
    }
    
    public static function badgeClass(string $priority): string
    {
        $classes = [
            self::LOW => 'badge-secondary',
            self::NORMAL => 'badge-info',
            self::HIGH => 'badge-warning',
            self::URGENT => 'badge-danger'
        ];
        
        return $classes[$priority] ?? 'badge-info';
    }
}