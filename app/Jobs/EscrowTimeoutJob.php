<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LoggerInterface;
use App\Domain\Financial\Services\FinancialEscrowService;

/**
 * EscrowTimeoutJob
 *
 * آزادسازی خودکار escrowهای منقضی که به صورت pending مانده‌اند.
 * از منطق FinancialEscrowService::releaseExpiredHolds استفاده می‌کند.
 */
class EscrowTimeoutJob
{
    private FinancialEscrowService $escrowService;
    private LoggerInterface $logger;
    public function __construct(
        FinancialEscrowService $escrowService,
        LoggerInterface $logger
    ) {        $this->escrowService = $escrowService;
        $this->logger = $logger;
}

    public function handle(array $data = []): void
    {
        try {
            $released = $this->escrowService->releaseExpiredHolds();

            if ($released > 0) {
                $this->logger->info('escrow.timeout_released', ['released' => $released]);
            } else {
                $this->logger->info('escrow.timeout_nothing_to_release', []);
            }
        } catch (\Throwable $e) {
            $this->logger->error('escrow.timeout_failed', ['error' => $e->getMessage()]);
        }
    }
}
