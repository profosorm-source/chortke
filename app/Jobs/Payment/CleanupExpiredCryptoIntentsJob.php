<?php

declare(strict_types=1);

namespace App\Jobs\Payment;

class CleanupExpiredCryptoIntentsJob
{
    public function __construct(
        
    ) {}

    public function handle(): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Payment\CleanupExpiredCryptoIntentsJob::class);
        return $job->handle();
    }
}
