<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\LoggerInterface;
use App\Services\InvestmentService;
use App\Services\SettingService;
use Core\Database;

/**
 * InvestmentProfitDistributionJob
 *
 * توزیع سود/ضرر سرمایه‌گذاری‌ها به صورت دوره‌ای.
 * - لیست سرمایه‌گذاری‌های فعال را فراخوانی می‌کند.
 * - در صورت عدم ارسال داده، از آخرین ترید بسته‌شده و تنظیمات پیش‌فرض استفاده می‌کند.
 */
class InvestmentProfitDistributionJob
{
    private const SYSTEM_ADMIN_ID = 0;

    public function __construct(
        private InvestmentService $investmentService,
        private Database $db,
        private SettingService $settingService,
        private LoggerInterface $logger
    ) {}

    public function handle(array $data = []): void
    {
        $tradingRecordId   = (int) ($data['trading_record_id'] ?? 0);
        $profitLossPercent = array_key_exists('profit_loss_percent', $data) ? (float) $data['profit_loss_percent'] : null;
        $period            = (string) ($data['period'] ?? 'weekly');
        $adminId           = (int) ($data['admin_id'] ?? self::SYSTEM_ADMIN_ID);

        if ($tradingRecordId <= 0) {
            $tradingRecordId = $this->getLatestClosedTradingRecordId();
        }

        if ($profitLossPercent === null) {
            $profitLossPercent = (float) $this->settingService->get('investment_default_profit_loss_percent', 0.0);
        }

        if ($tradingRecordId <= 0 || $profitLossPercent === 0.0) {
            $this->logger->warning('investment.profit_distribution_skipped', [
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
            ]);
            return;
        }

        try {
            $rows = $this->db->fetchAll(
                "SELECT id FROM investments WHERE status = 'active'"
            );

            $investmentIds = array_map(static fn($row): int => (int) $row->id, $rows);

            if (empty($investmentIds)) {
                $this->logger->info('investment.profit_distribution_no_active_investments', [
                    'period' => $period,
                ]);
                return;
            }

            $result = $this->investmentService->applyProfitLossToBatch(
                $investmentIds,
                $tradingRecordId,
                $profitLossPercent,
                $period,
                $adminId
            );

            $this->logger->info('investment.profit_distribution_job_completed', [
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
                'admin_id'            => $adminId,
                'result'              => $result,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('investment.profit_distribution_job_failed', [
                'error'               => $e->getMessage(),
                'trading_record_id'   => $tradingRecordId,
                'profit_loss_percent' => $profitLossPercent,
                'period'              => $period,
            ]);
        }
    }

    private function getLatestClosedTradingRecordId(): int
    {
        try {
            $row = $this->db->fetch(
                "SELECT id FROM trading_records
                 WHERE status IN (?, ?) AND is_deleted = 0
                 ORDER BY close_time DESC
                 LIMIT 1",
                ['closed', 'stopped']
            );

            return $row ? (int) $row->id : 0;
        } catch (\Throwable $e) {
            $this->logger->warning('investment.profit_distribution_no_trading_record', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
