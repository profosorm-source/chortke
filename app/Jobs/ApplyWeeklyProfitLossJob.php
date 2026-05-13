<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\InvestmentService;

class ApplyWeeklyProfitLossJob
{
    private InvestmentService $investmentService;

    public function __construct(InvestmentService $investmentService)
    {
        $this->investmentService = $investmentService;
    }

    public function handle(array $data): void
    {
        $investmentIds = $data['investment_ids'] ?? [];
        $tradingRecordId = (int)($data['trading_record_id'] ?? 0);
        $profitLossPercent = (float)($data['profit_loss_percent'] ?? 0);
        $period = $data['period'] ?? '';
        $adminId = (int)($data['admin_id'] ?? 0);

        if (empty($investmentIds) || !$tradingRecordId) {
            return;
        }

        $this->investmentService->applyProfitLossToBatch($investmentIds, $tradingRecordId, $profitLossPercent, $period, $adminId);
    }
}
