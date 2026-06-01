<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class CloseTradeJob
{
    private \App\Models\TradingRecord $tradingModel;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Models\TradingRecord $tradingModel,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->tradingModel = $tradingModel;
        $this->logger = $logger;
}

    public function handle(int $tradeId, int $adminId, array $data): array
    {
        $request = new CloseTradeRequest($data);
        $validated = $request->validateOrFail();

        $trade = $this->tradingModel->find($tradeId);
        if (!$trade) {
            return ['success' => false, 'message' => 'ترید یافت نشد.'];
        }
        if ($trade->status !== TradingRecord::STATUS_OPEN) {
            return ['success' => false, 'message' => 'فقط تریدهای باز قابل بستن هستند.'];
        }

        $this->tradingModel->update($tradeId, [
            'close_price'         => $validated['close_price'],
            'closed_at'           => $validated['close_time'] ?? date('Y-m-d H:i:s'),
            'profit_loss_amount'  => $validated['profit_loss_amount'],
            'status'              => $validated['status'] ?? TradingRecord::STATUS_CLOSED,
            'reason'              => $validated['notes'] ?? $trade->reason,
        ]);

        $this->logger->info('trade_closed', ['message' => "Admin {$adminId} closed trade #{$tradeId}"]);

        return ['success' => true, 'message' => 'ترید بسته شد.'];
    }
}
