<?php
// app/Services/InvestmentService.php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use App\Services\Financial\CurrencyService;
use App\Validators\Requests\CreateInvestmentRequest;
use App\Validators\Requests\CreateTradeRequest;
use App\Validators\Requests\CloseTradeRequest;
use App\Validators\Requests\RequestWithdrawalRequest;
use App\Contracts\WalletServiceInterface;
use App\Services\Settings\AppSettings;
use Core\Database;
use App\Services\StateMachineService;
use Core\EventDispatcher;
use App\Events\InvestmentCreatedEvent;
use App\Services\FeatureFlagService;

class InvestmentService
{
    private Investment           $investmentModel;
    private TradingRecord        $tradingModel;
    private InvestmentProfit     $profitModel;
        private AppSettings       $settingService;
        private StateMachineService  $stateMachine;
            private const RISK_WARNING = <<<EOT
⚠️ هشدار ریسک سرمایه‌گذاری

سرمایه‌گذاری در بازارهای مالی (فارکس/طلا) دارای ریسک بالایی است.

۱. احتمال ضرر تا ۱۰۰٪ سرمایه وجود دارد.
۲. سیستم هیچ تضمینی برای سودآوری نمی‌دهد.
۳. عملکرد گذشته تضمینی برای آینده نیست.
۴. فقط پولی را سرمایه‌گذاری کنید که توان از دست دادن آن را دارید.
۵. مسئولیت کامل سرمایه‌گذاری با شما است.

با تأیید، اعلام می‌کنید که این ریسک‌ها را درک کرده و می‌پذیرید.
EOT;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Models\Investment $investmentModel,
        \App\Services\Settings\AppSettings $appSettings
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;

        $this->investmentModel = $investmentModel;
        $this->appSettings = $appSettings;
    }

    /**
     * الگوریتم کلاسیک (V1) محاسبه کارمزد و مالیات
     */
    protected function calculateNetProfitV1(float $profitLossAmount, float $siteFeePercent, float $taxPercent): array
    {
        $siteFee   = round($profitLossAmount * ($siteFeePercent / 100), 2);
        $afterFee  = $profitLossAmount - $siteFee;
        $taxAmount = round($afterFee * ($taxPercent / 100), 2);
        $netAmount = round($afterFee - $taxAmount, 2);
        return [$siteFee, $taxAmount, $netAmount];
    }

    /**
     * الگوریتم پیشرفته (V2) محاسبه کارمزد و مالیات با درصد پلکانی
     */
    protected function calculateNetProfitV2(float $profitLossAmount, float $investAmount, float $baseSiteFeePercent, float $taxPercent, array $tiers): array
    {
        // پیدا کردن درصد کارمزد مرتبط بر اساس مبلغ سرمایه‌گذاری
        // فرض می‌کنیم Tiers مرتب شده باشند (از کوچک به بزرگ)
        $effectiveFeePercent = $baseSiteFeePercent;
        
        // sort tiers by min amount ascending
        usort($tiers, function ($a, $b) {
            return ($a['min'] ?? 0) <=> ($b['min'] ?? 0);
        });

        foreach ($tiers as $tier) {
            $minAmount = (float)($tier['min'] ?? 0);
            if ($investAmount >= $minAmount) {
                $effectiveFeePercent = (float)($tier['fee'] ?? $baseSiteFeePercent);
            }
        }

        // مطمئن می‌شویم کارمزد از ۰ کمتر نشود
        $effectiveFeePercent = max(0, $effectiveFeePercent);

        $siteFee   = round($profitLossAmount * ($effectiveFeePercent / 100), 2);
        $afterFee  = $profitLossAmount - $siteFee;
        $taxAmount = round($afterFee * ($taxPercent / 100), 2);
        $netAmount = round($afterFee - $taxAmount, 2);
        
        return [$siteFee, $taxAmount, $netAmount];
    }

    /**
     * ثبت ترید جدید (ادمین)
     */
    public function createTrade(int $adminId, array $data): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Investment\CreateTradeJob::class);
        return $job->handle($adminId, $data);
    }

    /**
     * بستن ترید (ادمین)
     */
    public function closeTrade(int $tradeId, int $adminId, array $data): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Investment\CloseTradeJob::class);
        return $job->handle($tradeId, $adminId, $data);
    }

    /**
     * اعمال سود/ضرر هفتگی بر تمام سرمایه‌گذاری‌های فعال (ادمین)
     */
    /**
     * اعمال سود/ضرر هفتگی بر تمام سرمایه‌گذاری‌های فعال (ادمین) - صف‌بندی‌شده
     */
    public function applyWeeklyProfitLoss(int $adminId, int $tradingRecordId, float $profitLossPercent, string $period): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Investment\ApplyWeeklyProfitLossJob::class);
        return $job->handle($adminId, $tradingRecordId, $profitLossPercent, $period);
    }

    /**
     * اعمال سود/ضرر بر روی یک بچ خاص از سرمایه‌گذاری‌ها (اجرا توسط Queue)
     * ✅ OPTIMIZATION: Use bulkFetch() instead of loop with individual find() calls
     */
    public function applyProfitLossToBatch(array $investmentIds, int $tradingRecordId, float $percent, string $period, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Investment\ApplyProfitLossToBatchJob::class);
        return $job->handle($investmentIds, $tradingRecordId, $percent, $period, $adminId);
    }

    /**
     * درخواست برداشت سود
     */
    public function requestWithdrawal(int $userId, array $data): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Investment\RequestWithdrawalJob::class);
        return $job->handle($userId, $data);
    }

    /**
     * تأیید و پرداخت برداشت (ادمین)
     */
    public function approveWithdrawal(int $withdrawalId, int $adminId): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Investment\ApproveWithdrawalJob::class);
        return $job->handle($withdrawalId, $adminId);
    }

    /**
     * رد درخواست برداشت (ادمین)
     */
    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Investment\RejectWithdrawalJob::class);
        return $job->handle($withdrawalId, $adminId, $reason);
    }

    /**
     * H-I3 Fix: Solvency Report for the Investment System
     */
    public function getSolvencyReport(): array
    {
        // Total active user balances (Total Liabilities)
        $totalInvestments = (float)($this->db->query(
            "SELECT SUM(current_balance) FROM investments WHERE status = 'active' AND deleted_at IS NULL"
        )->fetchColumn() ?? 0);

        if ($totalInvestments <= 0) {
            return ['ratio' => 1.0, 'shortfall' => 0.0, 'status' => 'solvent'];
        }

        // Total active capital initially invested by users
        $totalInitialInvested = (float)($this->db->query(
            "SELECT SUM(amount) FROM investments WHERE status = 'active' AND deleted_at IS NULL"
        )->fetchColumn() ?? 0);

        // Sum of all manual trading profit/loss amounts logged by admins
        $totalTradingProfitLoss = (float)($this->db->query(
            "SELECT SUM(profit_loss_amount) FROM trading_records WHERE is_deleted = 0"
        )->fetchColumn() ?? 0);

        // Total Real Assets currently backing user funds
        $realAssets = $totalInitialInvested + $totalTradingProfitLoss;
        
        $ratio = $realAssets / $totalInvestments;

        if ($ratio < 0.9) {
            $this->logger->critical("Solvency alert! Solvency ratio has dropped below 90% (" . round($ratio * 100, 2) . "%)");
            $this->auditTrail->record('system.solvency_alert', 0, [
                'ratio' => $ratio,
                'total_investments' => $totalInvestments,
                'real_assets' => $realAssets,
                'shortfall' => max(0, $totalInvestments - $realAssets)
            ]);
        }

        return [
            'ratio' => $ratio,
            'shortfall' => max(0, $totalInvestments - $realAssets),
            'total_investments' => $totalInvestments,
            'real_assets' => $realAssets,
            'status' => $ratio >= 0.9 ? 'solvent' : 'insolvent'
        ];
    }

    public function getRiskWarning(): string
    {
        return self::RISK_WARNING;
    }

    public function getSettings(): array
    {
        return [
            'min_amount'          => (float)$this->appSettings->get('investment_min_amount', 10),
            'max_amount'          => (float)$this->appSettings->get('investment_max_amount', 10000),
            'site_fee_percent'    => (float)$this->appSettings->get('investment_site_fee_percent', 10),
            'tax_percent'         => (float)$this->appSettings->get('investment_tax_percent', 9),
            'withdrawal_cooldown' => Investment::WITHDRAWAL_COOLDOWN_DAYS,
            'deposit_lock'        => Investment::DEPOSIT_LOCK_DAYS,
        ];
    }

    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        // Centralized Delegation to Model leveraging the optimized Filterable Trait system
        return $this->investmentModel->searchNative($q, $filters, $limit, $offset);
    }
}
