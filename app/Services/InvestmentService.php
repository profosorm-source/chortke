<?php
// app/Services/InvestmentService.php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Services\Notification\NotificationService;
use App\Services\User\UserService;
use App\Services\Shared\ReferralService;
use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use Core\Database;
use App\Services\AuditTrail;
use App\Services\SettingService;
use App\Services\PerformanceOptimizationService;
use App\Contracts\CurrencyServiceInterface;

class InvestmentService extends \App\Services\BaseService
{
    private Database             $db;
    private WalletService        $walletService;
    private NotificationService  $notificationService;
    private UserService          $userService;
    private ReferralService      $referralService;
    private Investment           $investmentModel;
    private TradingRecord        $tradingModel;
    private InvestmentProfit     $profitModel;
    private InvestmentWithdrawal $withdrawalModel;
    private AuditTrail           $auditTrail;
	private \Core\Queue $queue;
    private SettingService       $settingService;
    private PerformanceOptimizationService $performance;
    private CurrencyServiceInterface $currencyService;
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

    public function __construct(
    Database $db,
    WalletService $walletService,
    NotificationService $notificationService,
    UserService $userService,
    ReferralService $referralService,
    \App\Models\Investment $investmentModel,
    \App\Models\TradingRecord $tradingModel,
    \App\Models\InvestmentProfit $profitModel,
    \App\Models\InvestmentWithdrawal $withdrawalModel,
    AuditTrail $auditTrail,
    LoggerInterface $logger,
    \Core\Queue $queue,
    SettingService $settingService,
    PerformanceOptimizationService $performance,
    CurrencyServiceInterface $currencyService
) {
        parent::__construct($logger);
        $this->db                  = $db;
        $this->investmentModel     = $investmentModel;
        $this->tradingModel        = $tradingModel;
        $this->profitModel         = $profitModel;
        $this->withdrawalModel     = $withdrawalModel;
        $this->walletService       = $walletService;
        $this->notificationService = $notificationService;
        $this->userService         = $userService;
        $this->referralService     = $referralService;
        $this->auditTrail = $auditTrail;
        $this->queue = $queue;
        $this->settingService = $settingService;
        $this->performance = $performance;
        $this->currencyService = $currencyService;
    }

    /**
     * ثبت سرمایه‌گذاری جدید
     * 
     * این متد یک transaction واحد دارد و walletService را بدون transaction فراخوانی می‌کند.
     * برای تفکیک مسئولیت، باید walletService::_depositUnsafe استفاده شود.
     */
        public function createInvestment(int $userId, array $data): array
    {
        $amount = (float)($data['amount'] ?? 0);

        $minAmount = (float)$this->settingService->get('investment_min_amount', 10);
        $maxAmount = (float)$this->settingService->get('investment_max_amount', 10000);

        if ($amount < $minAmount) {
            return ['success' => false, 'message' => "حداقل مبلغ سرمایه‌گذاری " . $this->currencyService->formatAmount($minAmount, 'usdt') . " است."];
        }
        if ($amount > $maxAmount) {
            return ['success' => false, 'message' => "حداکثر مبلغ سرمایه‌گذاری " . $this->currencyService->formatAmount($maxAmount, 'usdt') . " است."];
        }
        
        // Pre-validation (non-locking)
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'مبلغ سرمایه‌گذاری نامعتبر است'];
        }

        $this->db->beginTransaction();

        try {
            // H-I2 & H-I3 Fix: Move critical checks INSIDE transaction with lock
            $wallet = $this->walletService->getOrCreateWallet($userId);
            // Re-fetch with FOR UPDATE
            $walletRecord = $this->db->selectOne("SELECT usdt_balance FROM wallets WHERE user_id = ? FOR UPDATE", [$userId]);
            
            if (!$walletRecord || (float)$walletRecord->usdt_balance < $amount) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی تتری کافی نیست'];
            }

            // H-I3: Check active investment with lock
            $activeCount = $this->db->query("SELECT COUNT(*) FROM investments WHERE user_id = ? AND status = 'active' FOR UPDATE", [$userId])->fetchColumn();
            if ($activeCount > 0) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'شما یک سرمایه‌گذاری فعال دارید'];
            }

            $idempotencyKey = \Core\IdempotencyKey::generateFromPayload('investment_creation', [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => 'usdt'
            ]);

            // ۱. کسر موجودی کیف‌پول
            $payResult = $this->walletService->pay(
                $userId,
                $amount,
                'usdt',
                [
                    'type' => 'investment_creation',
                    'description' => 'سرمایه‌گذاری جدید',
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (empty($payResult['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در کسر موجودی: ' . ($payResult['message'] ?? '')];
            }

            // ۲. ثبت سرمایه‌گذاری
            $investmentId = $this->investmentModel->create([
                'user_id' => $userId,
                'amount' => $amount,
                'current_balance' => $amount,
                'status' => \App\Models\Investment::STATUS_ACTIVE,
                'transaction_id' => $payResult['transaction_id'] ?? null,
            ]);
            
            $this->db->commit();

            // ۳. پورسانت شبکه ارجاع (Referral) صندوق سرمایه گذاری (پس از موفقیت در commit اصلی)
            try {
                $userRecord = $this->userService->findById($userId);
                if ($userRecord && !empty($userRecord->referred_by)) {
                    $this->referralService->processCommission((int)$userRecord->referred_by, $amount, 'usdt', [
                        'action' => 'investment_creation',
                        'investor_id' => $userId,
                        'investment_id' => $investmentId
                    ]);
                }
            } catch (\Throwable $commissionEx) {
                $this->logger->error('investment_commission_post_commit_failed', [
                    'user_id' => $userId,
                    'investment_id' => $investmentId,
                    'error' => $commissionEx->getMessage()
                ]);
            }

            $this->auditTrail->record('investment.created', $userId, [
                'investment_id' => $investmentId,
                'amount' => $amount,
            ]);

            $this->notify($userId, 'سرمایه‌گذاری جدید', "سرمایه‌گذاری " . $this->currencyService->formatAmount($amount, 'usdt') . " با موفقیت ثبت شد.", 'investment_created');
            $this->logger->info('investment_created', ['message' => "User {$userId} invested {$amount} USDT", 'id' => $investmentId]);

            return ['success' => true, 'message' => 'سرمایه‌گذاری با موفقیت انجام شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('investment_create_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در ثبت سرمایه‌گذاری'];
        }
    }

    /**
     * ثبت ترید جدید (ادمین)
     */
    public function createTrade(int $adminId, array $data): array
    {
        $tradeId = $this->tradingModel->create([
            'admin_id'            => $adminId,
            'direction'           => $data['direction'],
            'pair'                => $data['pair'] ?? 'XAUUSD',
            'amount'              => $data['lot_size'] ?? 0,
            'open_price'          => $data['open_price'],
            'close_price'         => $data['close_price'] ?? null,
            'stop_loss'           => $data['stop_loss'] ?? null,
            'take_profit'         => $data['take_profit'] ?? null,
            'profit_loss_amount'  => $data['profit_loss_amount'] ?? 0,
            'currency'            => 'usdt',
            'status'              => !empty($data['close_time']) ? TradingRecord::STATUS_CLOSED : TradingRecord::STATUS_OPEN,
            'reason'              => $data['notes'] ?? null,
            'user_id'             => $data['user_id'] ?? null,
            'investment_id'       => $data['investment_id'] ?? null,
        ]);

        if (!$tradeId) {
            return ['success' => false, 'message' => 'خطا در ثبت ترید.'];
        }

        $this->auditTrail->record('admin.settings.changed', null, [
            'action'   => 'trade_created',
            'trade_id' => $tradeId,
            'admin_id' => $adminId,
        ], $adminId);

        $this->logger->info('trade_created', ['message' => "Admin {$adminId} created trade #{$tradeId}"]);

        return ['success' => true, 'message' => 'ترید با موفقیت ثبت شد.', 'trade_id' => $tradeId];
    }

    /**
     * بستن ترید (ادمین)
     */
    public function closeTrade(int $tradeId, int $adminId, array $data): array
    {
        $trade = $this->tradingModel->find($tradeId);
        if (!$trade) {
            return ['success' => false, 'message' => 'ترید یافت نشد.'];
        }
        if ($trade->status !== TradingRecord::STATUS_OPEN) {
            return ['success' => false, 'message' => 'فقط تریدهای باز قابل بستن هستند.'];
        }

        $this->tradingModel->update($tradeId, [
            'close_price'         => $data['close_price'],
            'closed_at'           => $data['close_time'] ?? date('Y-m-d H:i:s'),
            'profit_loss_amount'  => $data['profit_loss_amount'],
            'status'              => $data['status'] ?? TradingRecord::STATUS_CLOSED,
            'reason'              => $data['notes'] ?? $trade->reason,
        ]);

        $this->logger->info('trade_closed', ['message' => "Admin {$adminId} closed trade #{$tradeId}"]);

        return ['success' => true, 'message' => 'ترید بسته شد.'];
    }

    /**
     * اعمال سود/ضرر هفتگی بر تمام سرمایه‌گذاری‌های فعال (ادمین)
     */
    /**
     * اعمال سود/ضرر هفتگی بر تمام سرمایه‌گذاری‌های فعال (ادمین) - صف‌بندی‌شده
     */
    public function applyWeeklyProfitLoss(int $adminId, int $tradingRecordId, float $profitLossPercent, string $period): array
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
        $batchSize = max(10, min(500, (int)$this->settingService->get('investment_batch_size', 100)));
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

    /**
     * اعمال سود/ضرر بر روی یک بچ خاص از سرمایه‌گذاری‌ها (اجرا توسط Queue)
     * ✅ OPTIMIZATION: Use bulkFetch() instead of loop with individual find() calls
     */
    public function applyProfitLossToBatch(array $investmentIds, int $tradingRecordId, float $percent, string $period, int $adminId): array
    {
        $this->db->beginTransaction();
        try {
            // H-I4 Fix: Idempotency check - has this record already been processed?
            $alreadyProcessed = $this->db->query("SELECT 1 FROM investment_profits WHERE trading_record_id = ? LIMIT 1", [$tradingRecordId])->fetch();
            if ($alreadyProcessed) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'این رکورد سود قبلاً اعمال شده است.'];
            }

            $investments = $this->investmentModel->findInIdsForUpdate($investmentIds);
            if (empty($investments)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'سرمایه‌گذاری فعالی یافت نشد.'];
            }

            // Index investments by ID for fast lookup
            $investmentMap = [];
            foreach ($investments as $inv) {
                $investmentMap[$inv->id] = $inv;
            }

            $siteFeePercent = (float)$this->settingService->get('investment_site_fee_percent', 10);
            $taxPercent     = (float)$this->settingService->get('investment_tax_percent', 9);
            $count          = 0;

            foreach ($investmentIds as $invId) {
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
                    $siteFee   = round($profitLossAmount * ($siteFeePercent / 100), 2);
                    $afterFee  = $profitLossAmount - $siteFee;
                    $taxAmount = round($afterFee * ($taxPercent / 100), 2);
                    $netAmount = round($afterFee - $taxAmount, 2);
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
                    $updateData['status']          = Investment::STATUS_FROZEN;
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
                $this->notify($inv->user_id,
                    "گزارش هفتگی سرمایه‌گذاری",
                    "دوره {$period}: {$typeLabel} {$amountFormatted} | موجودی جدید: " . $this->currencyService->formatAmount($balanceAfter, 'usdt'),
                    'investment_profit'
                );
                $count++;
            }

            // H-I7: Audit Trail
            app(\App\Services\AuditTrail::class)->record('investment.profit_batch_applied', $adminId, [
                'trading_record_id' => $tradingRecordId,
                'count' => count($investmentIds),
                'percent' => $percent,
                'period' => $period
            ]);

            $this->db->commit();
            return ['success' => true, 'processed' => count($investments)];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('investment_profit_error', ['message' => $e->getMessage()]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * درخواست برداشت سود
     */
       public function requestWithdrawal(int $userId, array $data): array
    {
        $this->db->beginTransaction();
        
        $investment = $this->db->query("SELECT * FROM investments WHERE user_id = ? AND status = ? FOR UPDATE", [$userId, \App\Models\Investment::STATUS_ACTIVE])->fetch(\PDO::FETCH_OBJ);
        if (!$investment) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'سرمایه‌گذاری فعالی ندارید.'];
        }

        $canWithdraw = $this->investmentModel->canWithdraw($userId);
        if (!$canWithdraw['allowed']) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $canWithdraw['reason']];
        }

        if ($this->withdrawalModel->hasPending($userId)) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'شما یک درخواست برداشت در حال بررسی دارید.'];
        }

        $withdrawType   = $data['withdrawal_type'] ?? \App\Models\InvestmentWithdrawal::TYPE_PROFIT_ONLY;
        $currentBalance = (float)$investment->current_balance;
        $originalAmount = (float)$investment->amount;

        if ($withdrawType === \App\Models\InvestmentWithdrawal::TYPE_PROFIT_ONLY) {
            $profit = $currentBalance - $originalAmount;
            if ($profit <= 0) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'سودی برای برداشت وجود ندارد. موجودی فعلی کمتر یا برابر سرمایه اولیه است.'];
            }
            $amount = $profit;
        } else {
            $amount = $currentBalance;
        }

        $idempotencyKey = \Core\IdempotencyKey::generateFromPayload('investment_withdraw', [
            'user_id' => $userId,
            'investment_id' => $investment->id,
            'amount' => $amount,
            'date' => date('Y-m-d')
        ]);

        $withdrawalId = $this->withdrawalModel->create([
            'investment_id'   => $investment->id,
            'user_id'         => $userId,
            'amount'          => $amount,
            'withdrawal_type' => $withdrawType,
            'status'          => \App\Models\InvestmentWithdrawal::STATUS_PENDING,
        ]);

        $this->db->commit();

        $this->auditTrail->record('investment.withdrawal_requested', $userId, [
            'withdrawal_id' => $withdrawalId,
            'amount'        => $amount,
            'type'          => $withdrawType,
        ]);

        return ['success' => true, 'message' => 'درخواست برداشت سود شما با موفقیت ثبت شد و در انتظار تأیید است.'];
    }
    /**
     * تأیید و پرداخت برداشت (ادمین)
     */
        public function approveWithdrawal(int $withdrawalId, int $adminId): array
    {
        $this->db->beginTransaction();

        $withdrawal = $this->db->query("SELECT * FROM investment_withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);

        if (!$withdrawal) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'درخواست یافت نشد'];
        }

        if ($withdrawal->status !== \App\Models\InvestmentWithdrawal::STATUS_PENDING) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'فقط درخواست‌های در انتظار قابل تأیید هستند'];
        }

        $investment = $this->db->query("SELECT * FROM investments WHERE id = ? FOR UPDATE", [$withdrawal->investment_id])->fetch(\PDO::FETCH_OBJ);

        if (!$investment) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'سرمایه‌گذاری یافت نشد'];
        }

        try {
            $idempotencyKey = "inv_withdrawal_{$withdrawalId}_" . time();
            $depositResult = $this->walletService->deposit(
                (int)$withdrawal->user_id,
                (float)$withdrawal->amount,
                'usdt',
                [
                    'type'          => 'investment_withdrawal',
                    'investment_id' => $investment->id,
                    'withdrawal_id' => $withdrawalId,
                    'description'   => 'برداشت سود سرمایه‌گذاری',
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (empty($depositResult['success'])) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در واریز: ' . ($depositResult['message'] ?? '')];
            }

            $this->withdrawalModel->update($withdrawalId, [
                'status'         => \App\Models\InvestmentWithdrawal::STATUS_COMPLETED,
                'processed_at'   => date('Y-m-d H:i:s'),
                'transaction_id' => $depositResult['transaction_id'] ?? null,
            ]);

            $newBalance  = (float)$investment->current_balance - $withdrawal->amount;
            $investUpdate = [
                'current_balance'      => max(0, $newBalance),
            ];

            if ($withdrawal->amount >= $investment->amount) {
                $investUpdate['status']          = \App\Models\Investment::STATUS_CLOSED;
                $investUpdate['current_balance'] = 0;
            }

            $this->investmentModel->update($investment->id, $investUpdate);

            $this->db->commit();

            $this->auditTrail->record('investment.closed', (int)$withdrawal->user_id, [
                'withdrawal_id'   => $withdrawalId,
                'investment_id'   => $investment->id,
                'amount'          => (float)$withdrawal->amount,
                'withdrawal_type' => ($withdrawal->amount >= $investment->amount ? 'full_close' : 'profit_only'),
                'admin_id'        => $adminId,
            ], $adminId);

            $this->notify($withdrawal->user_id, 'برداشت سرمایه‌گذاری تأیید شد',
                "مبلغ " . $this->currencyService->formatAmount((float)$withdrawal->amount, 'usdt') . " به کیف پول شما واریز شد",
                'investment_withdrawal_approved');

            $this->logger->info('investment_withdrawal_approved', ['message' => "Admin {$adminId} approved withdrawal #{$withdrawalId}"]);

            return ['success' => true, 'message' => 'برداشت تأیید و واریز شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('investment_withdrawal_approve_failed', [
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در تأیید برداشت'];
        }
    }

    /**
     * رد درخواست برداشت (ادمین)
     */
    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): array
    {
        $withdrawal = $this->withdrawalModel->find($withdrawalId);
        if (!$withdrawal || $withdrawal->status !== InvestmentWithdrawal::STATUS_PENDING) {
            return ['success' => false, 'message' => 'درخواست معتبر نیست.'];
        }

        $this->withdrawalModel->update($withdrawalId, [
            'status'           => InvestmentWithdrawal::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        $this->auditTrail->record('investment.closed', (int)$withdrawal->user_id, [
            'action'        => 'withdrawal_rejected',
            'withdrawal_id' => $withdrawalId,
            'reason'        => $reason,
            'admin_id'      => $adminId,
        ], $adminId);

        $this->notify($withdrawal->user_id, 'درخواست برداشت رد شد',
            "دلیل: {$reason}", 'investment_withdrawal_rejected');

        $this->logger->info('investment_withdrawal_rejected', ['message' => "Admin {$adminId} rejected withdrawal #{$withdrawalId}"]);

        return ['success' => true, 'message' => 'درخواست رد شد.'];
    }

    public function getRiskWarning(): string
    {
        return self::RISK_WARNING;
    }

    public function getSettings(): array
    {
        return [
            'min_amount'          => (float)$this->settingService->get('investment_min_amount', 10),
            'max_amount'          => (float)$this->settingService->get('investment_max_amount', 10000),
            'site_fee_percent'    => (float)$this->settingService->get('investment_site_fee_percent', 10),
            'tax_percent'         => (float)$this->settingService->get('investment_tax_percent', 9),
            'withdrawal_cooldown' => Investment::WITHDRAWAL_COOLDOWN_DAYS,
            'deposit_lock'        => Investment::DEPOSIT_LOCK_DAYS,
        ];
    }

    private function notify(int $userId, string $title, string $message, string $type): void
    {
        try {
            $this->notificationService->send($userId, $type, $title, $message);
        } catch (\Throwable $e) {
            $this->logger->error('notification_error', ['message' => $e->getMessage()]);
        }
    }

    public function searchInvestments(string $q, array $filters, int $limit, int $offset): array
    {
        // Centralized Delegation to Model leveraging the optimized Filterable Trait system
        return $this->investmentModel->searchNative($q, $filters, $limit, $offset);
    }
}

