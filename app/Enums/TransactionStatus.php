<?php
namespace App\Enums;

/**
 * Transaction Status Enum
 */
class TransactionStatus
{
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const COMPLETED = 'completed';
    const FAILED = 'failed';
    const CANCELLED = 'cancelled';
    const REFUNDED = 'refunded';
    
    public static function all()
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
        ];
    }
    
    public static function label($status)
    {
        $labels = [
            self::PENDING => 'در انتظار',
            self::PROCESSING => 'در حال پردازش',
            self::COMPLETED => 'تکمیل شده',
            self::FAILED => 'ناموفق',
            self::CANCELLED => 'لغو شده',
            self::REFUNDED => 'بازپرداخت شده',
        ];
        
        return $labels[$status] ?? 'نامشخص';
    }
    
    public static function color($status)
    {
        $colors = [
            self::PENDING => 'warning',
            self::PROCESSING => 'info',
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'secondary',
            self::REFUNDED => 'primary',
        ];
        
        return $colors[$status] ?? 'secondary';
    }
    
    /**
     * وضعیت‌های نهایی که دیگر تغییر نمی‌کنند
     */
    public static function isFinal($status)
    {
        return in_array($status, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
            self::REFUNDED,
        ]);
    }
}