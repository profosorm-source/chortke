<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * ClientInfoTrait - متدهای کمکی برای دریافت اطلاعات کلاینت
 * 
 * استفاده: برای Services که نیاز دارند IP و User-Agent را دریافت کنند
 * مثال: AuditTrail, AccountTakeoverService, etc.
 */
trait ClientInfoTrait
{
    /**
     * دریافت آی‌پی آدرس کلاینت (با پشتیبانی Proxy)
     */
    protected function clientIp(): string
    {
        // تشخیص محیط اجرا (CLI یا Queue Worker) جهت جلوگیری از باگ $_SERVER
        if (php_sapi_name() === 'cli' || defined('STDIN') || !isset($_SERVER['REMOTE_ADDR'])) {
            return '127.0.0.1'; // آی‌پی پیش‌فرض آفلاین
        }
        
        return function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * دریافت User-Agent مرورگر کلاینت
     */
    protected function userAgent(): string
    {
        if (php_sapi_name() === 'cli' || defined('STDIN') || !isset($_SERVER['HTTP_USER_AGENT'])) {
            return 'Background-Worker/1.0'; // مرورگر پیش‌فرض آفلاین
        }

        return function_exists('get_user_agent') ? get_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
    }

    /**
     * دریافت ID کاربر فعلی از Session
     */
    protected function currentUserId(): ?int
    {
        if (php_sapi_name() === 'cli' || defined('STDIN')) {
            // در پس‌زمینه نشست سشن وجود ندارد
            return null;
        }

        try {
            $session = \Core\Session::getInstance();
            return $session->get('user_id');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
