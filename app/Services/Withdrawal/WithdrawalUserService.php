<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

class WithdrawalUserService
{
    public function __construct() {}

    public function requestFromUser(int $userId, array $payload): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Withdrawal\RequestWithdrawalUserJob::class);
        return $job->handle($userId, $payload);
    }
}
