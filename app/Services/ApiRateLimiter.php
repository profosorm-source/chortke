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
class ApiRateLimiter extends \App\Services\BaseService
{
    private RateLimitPolicy $policy;

    public function __construct(RateLimitPolicy $policy, LoggerInterface $logger)
    {
        parent::__construct($logger);
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

