<?php

namespace App\Services;

use App\Policies\RateLimitPolicy;
use Core\Container;

use App\Contracts\LoggerInterface;
/**
 * ApiRateLimiter (Deprecated Wrapper)
 * 
 * این کلاس برای حفظ سازگاری با کنترلرها نگه داشته شده است.
 * لطفاً در کدهای جدید مستقیماً از App\Policies\RateLimitPolicy استفاده کنید.
 */
class ApiRateLimiter extends \App\Services\BaseService
{
    private RateLimitPolicy $policy;

    public function __construct(RateLimitPolicy $policy)
    {
        $this->policy = $policy;
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
        // For backwards compatibility, assume 1 as a generic return if needed, 
        // or properly proxy to a remaining method.
        return 1; 
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

