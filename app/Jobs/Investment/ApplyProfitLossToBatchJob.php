<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class ApplyProfitLossToBatchJob
{
    private \Core\Database $db;
    private \App\Models\Investment $investmentModel;
    private \App\Services\Settings\AppSettings $appSettings;
    private ?\App\Services\FeatureFlagService $featureFlagService;
    private \App\Models\InvestmentProfit $profitModel;
    private \App\Services\StateMachineService $stateMachine;
    private ?\App\Services\Financial\CurrencyService $currencyService;
    private \Core\EventDispatcher $eventDispatcher;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Models\Investment $investmentModel,
        \App\Services\Settings\AppSettings $appSettings,
        ?\App\Services\FeatureFlagService $featureFlagService = null,
        \App\Models\InvestmentProfit $profitModel,
        \App\Services\StateMachineService $stateMachine,
        ?\App\Services\Financial\CurrencyService $currencyService = null,
        \Core\EventDispatcher $eventDispatcher,
        \App\Contracts\LoggerInterface $logger
    ) {        $this->db = $db;
        $this->investmentModel = $investmentModel;
        $this->appSettings = $appSettings;
        $this->featureFlagService = $featureFlagService;
        $this->profitModel = $profitModel;
        $this->stateMachine = $stateMachine;
        $this->currencyService = $currencyService;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
}

    public function handle(array $investmentIds, int $tradingRecordId, float $percent, string $period, int $adminId): array
    {
        $this->db->beginTransaction();
        try {
            $unprocessedInvestmentIds = [];
            $investments = [];

            // H-I4 Fix: Exact Idempotency Check per investment to support safe retries in batched chunks
            $inClause = implode(',', array_fill(0, count($investmentIds), '?'));
            $stmt = $this->db->prepare(
                "SELECT investment_id FROM investment_profits 
                 WHERE trading_record_id = ? AND investment_id IN ($inClause)"
            );
            $stmt->execute(array_merge([$tradingRecordId], $investmentIds));
            $processedIds = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

            // Filter out already processed investment IDs to ensure exact idempotency without batch lockouts
            $unprocessedInvestmentIds = array_diff($investmentIds, $processedIds);

            if (empty($unprocessedInvestmentIds)) {
                throw new \Exception('BATCH_PROCESSED');
            }

            $investments = $this->investmentModel->findInIdsForUpdate($unprocessedInvestmentIds);
            if (empty($investments)) {
                throw new \Exception('سرمایه‌گذاری فعالی یافت نشد.');
            }

            // Index investments by ID for fast lookup
            $investmentMap = [];
            foreach ($investments as $inv) {
                $investmentMap[$inv->id] = $inv;
            }

            // استفاده از getConfig برای خواندن پویا از فیچرفلگ به جای خواندن فقط از محیط استاتیک
            $defaultSiteFeePercent = (float)$this->appSettings->get('investment_site_fee_percent', 10);
            $defaultTaxPercent     = (float)$this->appSettings->get('investment_tax_percent', 9);

            $siteFeePercent = (float)$this->featureFlagService->getConfig('investment_fees', 'site_fee_percent', $defaultSiteFeePercent);
            $taxPercent     = (float)$this->featureFlagService->getConfig('investment_fees', 'tax_percent', $defaultTaxPercent);
            
            // خواندن ساختار پلکانی کارمزد برای جریان V2
            // این تنظیمات را در قالب آرایه‌ای مثل `[['min' => 0, 'fee' => 10], ['min' => 1000, 'fee' => 8], ['min' => 5000, 'fee' => 5]]` در پنل قرار می‌دهیم
            $tiers = $this->featureFlagService->getConfig('investment_fees', 'fee_tiers', [
                ['min' => 0, 'fee' => $siteFeePercent], // دیفالت
                ['min' => 1000, 'fee' => max(0, $siteFeePercent - 2)], // کاهش 2 درصدی برای بالای 1000
                ['min' => 5000, 'fee' => max(0, $siteFeePercent - 5)], // کاهش 5 درصدی برای بالای 5000
                ['min' => 10000, 'fee' => max(0, $siteFeePercent - 8)], // کاهش 8 درصدی برای بالای 10000
            ]);

            $count          = 0;

            foreach ($unprocessedInvestmentIds as $invId) {
                $inv = $investmentMap[$invId] ?? null;
                if (!$inv || $inv->status !== Investment::STATUS_ACTIVE) {
                    continue;
                }

                $investAmount     = (float)$inv->current_balance;
                $profitLossAmount = round($investAmount * ($percent / 100), 2);
                $isProfit         = $profitLossAmount >= 0;

                $siteFee   = 0;
                $taxAmount = 0;
                $netAmount = $profitLossAmount;

                if ($isProfit && $profitLossAmount > 0) {
                    // جریان‌های نسخه‌بندی شده (Versioned Flows)
                    if ($this->featureFlagService->isEnabled('investment_v2_calculation', $inv->user_id)) {
                        list($siteFee, $taxAmount, $netAmount) = $this->calculateNetProfitV2($profitLossAmount, $investAmount, $siteFeePercent, $taxPercent, $tiers);
                    } else {
                        list($siteFee, $taxAmount, $netAmount) = $this->calculateNetProfitV1($profitLossAmount, $siteFeePercent, $taxPercent);
                    }
                }

                $balanceBefore = $investAmount;
                $balanceAfter  = round($investAmount + $netAmount, 2);

                $this->profitModel->create([
                    'investment_id'       => $inv->id,
                    'user_id'             => $inv->user_id,
                    'amount'              => $netAmount,
                    'trading_record_id'   => $tradingRecordId,
                    'currency'            => 'usdt',
                    'profit_type'         => $isProfit ? 'profit' : 'loss',
                    'status'              => 'paid',
                    'transaction_id'      => 'tx_' . bin2hex(random_bytes(16)),
                    'period_date'         => date('Y-m-d'),
                ]);

                $updateData = [
                    'current_balance'  => $balanceAfter,
                ];

                if ($balanceAfter <= 0) {
                    $updateData['current_balance'] = 0;
                    if ($this->stateMachine->canTransition('investment', $inv->status, Investment::STATUS_FROZEN)) {
                        $updateData['status']          = Investment::STATUS_FROZEN;
                    }
                }

                $this->investmentModel->update($inv->id, $updateData);

                $this->auditTrail->record('investment.profit.applied', (int)$inv->user_id, [
                    'investment_id'       => $inv->id,
                    'period'              => $period,
                    'profit_loss_percent' => $percent,
                    'net_amount'          => $netAmount,
                    'balance_before'      => $balanceBefore,
                    'balance_after'       => $balanceAfter,
                    'trading_record_id'   => $tradingRecordId,
                    'admin_id'            => $adminId,
                ], $adminId);

                $typeLabel       = $isProfit ? 'سود' : 'ضرر';
                $amountFormatted = $this->currencyService->formatAmount(abs($netAmount), 'usdt');
                
                $this->eventDispatcher->dispatchAsync('investment.profit_applied', [
                    'user_id' => $inv->user_id,
                    'amount_formatted' => $amountFormatted,
                    'period' => $period
                ]);
                $count++;
            }

            // H-I7: Audit Trail
            $this->auditTrail->record('investment.profit_batch_applied', $adminId, [
                'trading_record_id' => $tradingRecordId,
                'count' => count($unprocessedInvestmentIds),
                'percent' => $percent,
                'period' => $period
            ]);

            $this->db->commit();

            return ['success' => true, 'processed' => count($investments)];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($e->getMessage() === 'BATCH_PROCESSED') {
                return ['success' => true, 'message' => 'تمام سرمایه‌گذاری‌های این بچ قبلاً پردازش شده‌اند.', 'processed' => 0];
            }
            $this->logger->error('investment_profit_error', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
