<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class RejectCryptoDepositJob
{
    public function __construct(
        
    ) {}

    public function handle(int $adminId, int $depositId, string $reason): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Payment\RejectCryptoDepositJob::class);
        return $job->handle($adminId, $depositId, $reason);
    }
}
