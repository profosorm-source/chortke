<?php

declare(strict_types=1);

namespace App\Services;

use App\Policies\RateLimitPolicy;
use Core\Container;
use App\Contracts\LoggerInterface;

/**
 * ApiRateLimiter (Deprecated Wrapper)
 * 
 * این کلاس برای حفظ سازگاری با کنترلرها نگه داشته شده است.
 * لطفاً در کدهای جدید مستقیماً از App\Policies\RateLimitPolicy استفاده کنید.
 * 
 * @deprecated 2.0 Use App\Policies\RateLimitPolicy directly.
 */
class ApiRateLimiter
{
    private RateLimitPolicy $policy;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        RateLimitPolicy $policy
    )
    {        $this->logger = $logger;

                $this->policy = $policy;
        
        // M28 Fix: تولید اخطار رسمی و ثبت در لاگ به منظور آگاهی‌رسانی به توسعه‌دهندگان جهت مهاجرت به کلاس جدید
        @trigger_error(
            'Class App\Services\ApiRateLimiter is deprecated and scheduled for removal in v2.1. ' .
            'Please inject App\Policies\RateLimitPolicy directly instead.', 
            E_USER_DEPRECATED
        );
        
        // M-SRV-01 Fix: ثبت صریح در لاگ پروژه جهت مانیتورینگ دقیق بدون وابستگی به هندلر خطای عمومی PHP
        $this->logger->warning('deprecated.class_usage', [
            'class' => self::class,
            'scheduled_removal' => 'v2.1',
            'alternative' => 'App\Policies\RateLimitPolicy'
        ]);
    }

    public function check(string $action, int $userId, ?string $limitKey = null): bool
    {
        return $this->policy->check($action, $userId, $limitKey);
    }

    public function checkByIp(string $action, string $ip, ?string $limitKey = null): bool
    {
        return $this->policy->check($action, sha1($ip), $limitKey);
    }

    public function remaining(string $action, int $userId): int
    {
        return $this->policy->remaining($action, $userId);
    }

    public function retryAfter(string $action, int $userId): int
    {
        return $this->policy->retryAfter($action, $userId);
    }

    public function tooManyResponse(string $action, int $userId, bool $isAjax = false): never
    {
        $this->policy->tooManyResponse($action, $userId, $isAjax);
    }

    public static function enforce(string $action, int $userId, bool $isAjax = false): void
    {
        RateLimitPolicy::enforce($action, $userId, $isAjax);
    }
}

