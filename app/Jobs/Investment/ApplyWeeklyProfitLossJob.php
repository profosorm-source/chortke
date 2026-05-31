<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class ApplyWeeklyProfitLossJob
{
    public function __construct(
        private \App\Models\TradingRecord $tradingModel,
        private \App\Models\Investment $investmentModel,
        private \App\Services\Settings\AppSettings $appSettings,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $adminId, int $tradingRecordId, float $profitLossPercent, string $period): array
    {
        $trade = $this->tradingModel->find($tradingRecordId);
        if (!$trade) {
            return ['success' => false, 'message' => 'رکورد ترید یافت نشد.'];
        }

        $activeInvestments = $this->investmentModel->getAll(['status' => Investment::STATUS_ACTIVE], 10000, 0);

        if (empty($activeInvestments)) {
            return ['success' => false, 'message' => 'سرمایه‌گذاری فعالی یافت نشد.'];
        }

        // استخراج شناسه‌های سرمایه‌گذاری فعال
        $investmentIds = [];
        foreach ($activeInvestments as $inv) {
            $investmentIds[] = $inv->id;
        }

        // شکستن شناسه‌ها به بچ‌های ۱۰۰ تایی
        $batchSize = max(10, min(500, (int)$this->appSettings->get('investment_batch_size', 100)));
        $chunks = array_chunk($investmentIds, $batchSize);

        $queue = $this->queue;
        $queuedJobs = 0;

        foreach ($chunks as $chunk) {
            $queue->push(\App\Jobs\ApplyWeeklyProfitLossJob::class, [
                'investment_ids'      => $chunk,
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
                'admin_id'            => $adminId,
            ]);
            $queuedJobs++;
        }

        $this->logger->info('investment_weekly_apply_queued', [
            'message' => "Admin {$adminId} queued {$profitLossPercent}% profit/loss for {$period} in {$queuedJobs} batch jobs, affecting " . count($investmentIds) . " investments."
        ]);

        return [
            'success' => true,
            'message' => "عملیات اعمال سود/ضرر هفتگی به صورت پس‌زمینه برای " . count($investmentIds) . " سرمایه‌گذاری در قالب {$queuedJobs} تسک صف‌بندی شد."
        ];
    }
}
