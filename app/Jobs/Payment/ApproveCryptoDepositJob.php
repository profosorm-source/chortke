<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class ApproveCryptoDepositJob
{
    public function __construct(
        
    ) {}

    public function handle(int $adminId, int $depositId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Payment\ApproveCryptoDepositJob::class);
        return $job->handle($adminId, $depositId);
    }
}
