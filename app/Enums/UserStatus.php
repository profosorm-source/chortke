<?php
namespace App\Enums;

/**
 * User Status Enum
 */
class UserStatus
{
    const INACTIVE = 'inactive';
    const ACTIVE = 'active';
    const SUSPENDED = 'suspended';
    const BANNED = 'banned';
    
    /**
     * دریافت تمام وضعیت‌ها
     */
    public static function all()
    {
        return [
            self::INACTIVE,
            self::ACTIVE,
            self::SUSPENDED,
            self::BANNED,
        ];
    }
    
    /**
     * دریافت برچسب فارسی
     */
    public static function label($status)
    {
        $labels = [
            self::INACTIVE => 'غیرفعال',
            self::ACTIVE => 'فعال',
            self::SUSPENDED => 'تعلیق شده',
            self::BANNED => 'مسدود شده',
        ];
        
        return $labels[$status] ?? 'نامشخص';
    }
    
    /**
     * دریافت رنگ Badge
     */
    public static function color($status)
    {
        $colors = [
            self::INACTIVE => 'secondary',
            self::ACTIVE => 'success',
            self::SUSPENDED => 'warning',
            self::BANNED => 'danger',
        ];
        
        return $colors[$status] ?? 'secondary';
    }
    
    /**
     * بررسی معتبر بودن
     */
    public static function isValid($status)
    {
        return in_array($status, self::all());
    }
}