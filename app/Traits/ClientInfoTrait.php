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
        return get_client_ip();
    }

    /**
     * دریافت User-Agent مرورگر کلاینت
     */
    protected function userAgent(): string
    {
        return get_user_agent();
    }

    /**
     * دریافت ID کاربر فعلی از Session
     */
    protected function currentUserId(): ?int
    {
        $session = \Core\Session::getInstance();
        return $session->get('user_id');
    }
}
