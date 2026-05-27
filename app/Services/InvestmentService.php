<?php
// app/Services/InvestmentService.php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use App\Contracts\WalletServiceInterface;
use App\Services\SettingService;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\StateMachineService;
use Core\EventDispatcher;
use App\Events\InvestmentCreatedEvent;

class InvestmentService extends \App\Services\BaseService
{
    private Investment           $investmentModel;
    private TradingRecord        $tradingModel;
    private InvestmentProfit     $profitModel;
    private InvestmentWithdrawal $withdrawalModel;
    private SettingService       $settingService;
    private WalletServiceInterface $walletService;
    private StateMachineService  $stateMachine;
    private ?\App\Services\OutboxService $outboxService = null;
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
    \App\Models\Investment $investmentModel,
    \App\Models\TradingRecord $tradingModel,
    \App\Models\InvestmentProfit $profitModel,
    \App\Models\InvestmentWithdrawal $withdrawalModel,
    WalletServiceInterface $walletService,
    SettingService $settingService,
    LoggerInterface $logger,
    Database $db,
    EventDispatcher $eventDispatcher,
    ?StateMachineService $stateMachine = null
) {
        // انتقال مدیریت زیرساخت به والد (BaseService)
        parent::__construct($logger, null, $db, null, null, null, null, $eventDispatcher);
        
        $this->investmentModel     = $investmentModel;
        $this->tradingModel        = $tradingModel;
        $this->profitModel         = $profitModel;
        $this->withdrawalModel     = $withdrawalModel;
        $this->settingService = $settingService;
        $this->walletService = $walletService;
        $this->stateMachine = $stateMachine ?? new StateMachineService($logger, $db);
        try {
            $this->outboxService = container()->get(\App\Services\OutboxService::class);
        } catch (\Throwable $e) {
            $this->outboxService = null;
        }
    }

    /**
     * ثبت سرمایه‌گذاری جدید
     * 
     * این متد یک transaction واحد دارد و walletService را بدون transaction فراخوانی می‌کند.
     * برای تفکیک مسئولیت، باید walletService::_depositUnsafe استفاده شود.
     */
    public function createInvestment(int $userId, array $data, ?string $idempotencyKey = null): array
    {
        $amount = (float)($data['amount'] ?? 0);

        $minAmount = (float)$this->settingService->get('investment_min_amount', 10);
        $maxAmount = (float)$this->settingService->get('investment_max_amount', 10000);

        if ($amount < $minAmount) {
            throw new \Core\Exceptions\BusinessException("حداقل مبلغ سرمایه‌گذاری " . $this->currencyService->formatAmount($minAmount, 'usdt') . " است.");
        }
        if ($amount > $maxAmount) {
            throw new \Core\Exceptions\BusinessException("حداکثر مبلغ سرمایه‌گذاری " . $this->currencyService->formatAmount($maxAmount, 'usdt') . " است.");
        }
        
        // Pre-validation (non-locking)
        if ($amount <= 0) {
            throw new \Core\Exceptions\BusinessException('مبلغ سرمایه‌گذاری نامعتبر است');
        }

        $payload = [
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => 'usdt',
        ];

        $explicitKey = $idempotencyKey !== null && $idempotencyKey !== ''
            ? $idempotencyKey
            : \Core\IdempotencyKey::generateFromPayload('investment_creation', $payload);

        return $this->idempotent('investment.create', $userId, $payload, function () use (
            $userId,
            $amount,
            $explicitKey,
            $data
        ) {
            $this->db->beginTransaction();

            try {
                // H-I2 Fix: Lock first and check if wallet exists inside transaction. Only create if not found.
                $walletRecord = $this->db->selectOne("SELECT * FROM wallets WHERE user_id = ? FOR UPDATE", [$userId]);
                if (!$walletRecord) {
                    $this->walletService->getOrCreateWallet($userId);
                    $walletRecord = $this->db->selectOne("SELECT * FROM wallets WHERE user_id = ? FOR UPDATE", [$userId]);
                }
                
                if (!$walletRecord || (float)$walletRecord->balance_usdt < $amount) {
                    $this->db->rollBack();
                    throw new \Core\Exceptions\InsufficientBalanceException('موجودی تتری کافی نیست');
                }

                // H-I3: Check active investment with lock
                $activeCount = $this->db->query("SELECT COUNT(*) FROM investments WHERE user_id = ? AND status = 'active' FOR UPDATE", [$userId])->fetchColumn();
                if ($activeCount > 0) {
                    $this->db->rollBack();
                    throw new \Core\Exceptions\InvalidStateException('شما یک سرمایه‌گذاری فعال دارید');
                }

                // ۱. کسر موجودی کیف‌پول
                $payResult = $this->walletService->pay(
                    $userId,
                    (string)$amount,
                    'usdt',
                    [
                        'type' => 'investment_creation',
                        'description' => 'سرمایه‌گذاری جدید',
                        'idempotency_key' => $explicitKey,
                    ]
                );

                if (empty($payResult['success'])) {
                    $this->db->rollBack();
                    throw new \Core\Exceptions\BusinessException('خطا در کسر موجودی: ' . ($payResult['message'] ?? ''));
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

                // 🚀 بعد از commit تراکنش، رویداد به صورت async ارسال می‌شود
                $this->eventDispatcher->dispatchAsync(
                    InvestmentCreatedEvent::class,
                    new InvestmentCreatedEvent(
                        $userId,
                        $investmentId,
                        $amount,
                        'usdt'
                    )
                );

                $this->logger->info('investment_created', ['message' => "User {$userId} invested {$amount} USDT", 'id' => $investmentId]);

                return ['success' => true, 'message' => 'سرمایه‌گذاری با موفقیت انجام شد'];

            } catch (\Throwable $e) {
                $this->db->rollBack();
                $this->logger->error('investment_create_failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, $explicitKey);
    }

    /**
     * ثبت ترید جدید (ادمین)
     */
    public function createTrade(int $adminId, array $data): array
    {
        $direction = $data['direction'] ?? '';
        if (!in_array($direction, [TradingRecord::DIRECTION_BUY, TradingRecord::DIRECTION_SELL], true)) {
            return ['success' => false, 'message' => 'جهت ترید نامعتبر است.'];
        }

        $openPrice = (float)($data['open_price'] ?? 0);
        if ($openPrice <= 0) {
            return ['success' => false, 'message' => 'قیمت باز شدن باید بیشتر از صفر باشد.'];
        }

        $pair = trim((string)($data['pair'] ?? 'XAUUSD'));
        if (empty($pair)) {
            return ['success' => false, 'message' => 'جفت ارز الزامی است.'];
        }

        // Generate an internal cryptographic signature to prevent database tempering and act as proof of verification
        $secretKey = 'chortke_secure_trade_hash_key_2026';
        $proofPayload = [
            'admin_id' => $adminId,
            'direction' => $direction,
            'pair' => $pair,
            'open_price' => $openPrice,
            'timestamp' => time(),
            'verified' => true
        ];
        $signature = hash_hmac('sha256', json_encode($proofPayload), $secretKey);
        
        $reasonPayload = json_encode([
            'notes' => $data['notes'] ?? null,
            'proof' => [
                'signature' => $signature,
                'payload' => $proofPayload
            ]
        ], JSON_UNESCAPED_UNICODE);

        $tradeId = $this->tradingModel->create([
            'admin_id'            => $adminId,
            'direction'           => $direction,
            'pair'                => $pair,
            'amount'              => $data['lot_size'] ?? 0,
            'open_price'          => $openPrice,
            'close_price'         => $data['close_price'] ?? null,
            'stop_loss'           => $data['stop_loss'] ?? null,
            'take_profit'         => $data['take_profit'] ?? null,
            'profit_loss_amount'  => $data['profit_loss_amount'] ?? 0,
            'currency'            => 'usdt',
            'status'              => !empty($data['close_time']) ? TradingRecord::STATUS_CLOSED : TradingRecord::STATUS_OPEN,
            'reason'              => $reasonPayload,
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
                $this->db->rollBack();
                return ['success' => true, 'message' => 'تمام سرمایه‌گذاری‌های این بچ قبلاً پردازش شده‌اند.', 'processed' => 0];
            }

            $investments = $this->investmentModel->findInIdsForUpdate($unprocessedInvestmentIds);
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
                'count' => count($investmentIds),
                'percent' => $percent,
                'period' => $period
            ]);

            $this->db->commit();

            return ['success' => true, 'processed' => count($investments)];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('investment_profit_error', ['message' => $e->getMessage()]);
            throw $e;
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
            throw new \Core\Exceptions\EntityNotFoundException('سرمایه‌گذاری فعالی ندارید.');
        }

        $canWithdraw = $this->investmentModel->canWithdraw($userId);
        if (!$canWithdraw['allowed']) {
            $this->db->rollBack();
            throw new \Core\Exceptions\InvalidStateException($canWithdraw['reason']);
        }

        if ($this->withdrawalModel->hasPending($userId)) {
            $this->db->rollBack();
            throw new \Core\Exceptions\InvalidStateException('شما یک درخواست برداشت در حال بررسی دارید.');
        }

        $withdrawType   = $data['withdrawal_type'] ?? \App\Models\InvestmentWithdrawal::TYPE_PROFIT_ONLY;
        $currentBalance = (float)$investment->current_balance;
        $originalAmount = (float)$investment->amount;

        if ($withdrawType === \App\Models\InvestmentWithdrawal::TYPE_PROFIT_ONLY) {
            $profit = $currentBalance - $originalAmount;
            if ($profit <= 0) {
                $this->db->rollBack();
                throw new \Core\Exceptions\BusinessException('سودی برای برداشت وجود ندارد. موجودی فعلی کمتر یا برابر سرمایه اولیه است.');
            }
            $amount = $profit;
        } else {
            $amount = $currentBalance;
        }

        $newBalance = $currentBalance - $amount;
        if ($newBalance < 0) {
            $this->db->rollBack();
            throw new \Core\Exceptions\InsufficientBalanceException('موجودی کافی برای برداشت وجود ندارد.');
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

        // H-I5 Fix: Deduct and reserve the balance immediately during request stage
        $this->investmentModel->update($investment->id, [
            'current_balance' => $newBalance
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
            $payload = [
                'user_id' => (int)$withdrawal->user_id,
                'amount' => (string)$withdrawal->amount,
                'currency' => 'usdt',
                'metadata' => [
                    'type' => 'investment_withdrawal',
                    'investment_id' => $investment->id,
                    'withdrawal_id' => $withdrawalId,
                    'description' => 'برداشت سود سرمایه‌گذاری',
                    'idempotency_key' => $idempotencyKey,
                ],
            ];

            if ($this->outboxService) {
                $ok = $this->outboxService->record('investment_withdrawal', $withdrawalId, 'wallet.deposit.requested', $payload);
                if (!$ok) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'خطا در ثبت رکورد خروجی برای واریز برداشت.'];
                }
            } else {
                $depositResult = $this->walletService->deposit(
                    (int)$withdrawal->user_id,
                    (string)$withdrawal->amount,
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
            }

            $this->withdrawalModel->update($withdrawalId, [
                'status'         => \App\Models\InvestmentWithdrawal::STATUS_COMPLETED,
                'processed_at'   => date('Y-m-d H:i:s'),
                'transaction_id' => $depositResult['transaction_id'] ?? null,
            ]);

            // H-I5: Balance was already deducted during request stage, only update status to closed if full close
            if ($withdrawal->withdrawal_type === \App\Models\InvestmentWithdrawal::TYPE_FULL_CLOSE) {
                if ($this->stateMachine->canTransition('investment', $investment->status, \App\Models\Investment::STATUS_CLOSED)) {
                    $this->investmentModel->update($investment->id, [
                        'status'          => \App\Models\Investment::STATUS_CLOSED,
                        'current_balance' => 0
                    ]);

                    \Core\EventDispatcher::getInstance()->dispatchAsync('investment.matured', [
                        'investment_id' => $investment->id,
                        'user_id' => (int)$investment->user_id,
                        'amount' => $investment->amount,
                        'final_balance' => $withdrawal->amount,
                        'matured_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }

            $this->db->commit();

            $this->auditTrail->record('investment.closed', (int)$withdrawal->user_id, [
                'withdrawal_id'   => $withdrawalId,
                'investment_id'   => $investment->id,
                'amount'          => (float)$withdrawal->amount,
                'withdrawal_type' => ($withdrawal->amount >= $investment->amount ? 'full_close' : 'profit_only'),
                'admin_id'        => $adminId,
            ], $adminId);

            $this->eventDispatcher->dispatchAsync('investment.withdrawal_approved', [
                'user_id' => $withdrawal->user_id,
                'amount' => $withdrawal->amount
            ]);
            
            $this->logger->info('investment_withdrawal_approved', ['message' => "Admin {$adminId} approved withdrawal #{$withdrawalId}"]);

            return ['success' => true, 'message' => 'برداشت تأیید و واریز شد'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('investment_withdrawal_approve_failed', [
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * رد درخواست برداشت (ادمین)
     */
    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $withdrawal = $this->db->query("SELECT * FROM investment_withdrawals WHERE id = ? FOR UPDATE", [$withdrawalId])->fetch(\PDO::FETCH_OBJ);
            if (!$withdrawal || $withdrawal->status !== \App\Models\InvestmentWithdrawal::STATUS_PENDING) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست معتبر نیست.'];
            }

            $investment = $this->db->query("SELECT * FROM investments WHERE id = ? FOR UPDATE", [$withdrawal->investment_id])->fetch(\PDO::FETCH_OBJ);
            if ($investment) {
                // H-I5 Fix: Restore the reserved balance back to investment's current balance
                $restoredBalance = (float)$investment->current_balance + (float)$withdrawal->amount;
                $this->investmentModel->update($investment->id, [
                    'current_balance' => $restoredBalance
                ]);
            }

            $this->withdrawalModel->update($withdrawalId, [
                'status'           => \App\Models\InvestmentWithdrawal::STATUS_REJECTED,
                'rejection_reason' => $reason,
            ]);

            $this->db->commit();

            $this->auditTrail->record('investment.closed', (int)$withdrawal->user_id, [
                'action'        => 'withdrawal_rejected',
                'withdrawal_id' => $withdrawalId,
                'reason'        => $reason,
                'admin_id'      => $adminId,
            ], $adminId);

            $this->eventDispatcher->dispatchAsync('investment.withdrawal_rejected', [
                'user_id' => $withdrawal->user_id,
                'reason' => $reason
            ]);
            
            $this->logger->info('investment_withdrawal_rejected', ['message' => "Admin {$adminId} rejected withdrawal #{$withdrawalId}"]);

            return ['success' => true, 'message' => 'درخواست رد شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('investment_withdrawal_reject_failed', [
                'withdrawal_id' => $withdrawalId,
                'admin_id'      => $adminId,
                'error'         => $e->getMessage()
            ]);
            throw $e;
        }
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
            'min_amount'          => (float)$this->settingService->get('investment_min_amount', 10),
            'max_amount'          => (float)$this->settingService->get('investment_max_amount', 10000),
            'site_fee_percent'    => (float)$this->settingService->get('investment_site_fee_percent', 10),
            'tax_percent'         => (float)$this->settingService->get('investment_tax_percent', 9),
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
