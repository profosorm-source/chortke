<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

class CreateInvestmentJob
{
    public function __construct(
        private \App\Services\Settings\AppSettings $appSettings,
        private ?\App\Services\Financial\CurrencyService $currencyService = null,
        private ?\App\Services\Shared\IdempotencyService $idempotencyService = null,
        private \Core\Database $db,
        private \App\Contracts\WalletServiceInterface $walletService,
        private \App\Models\Investment $investmentModel,
        private \Core\EventDispatcher $eventDispatcher,
        private \App\Contracts\LoggerInterface $logger
    ) {}

    public function handle(int $userId, array $data, ?string $idempotencyKey = null): array
    {
        $minAmount = (float)$this->appSettings->get('investment_min_amount', 10);
        $maxAmount = (float)$this->appSettings->get('investment_max_amount', 10000);

        $request = new CreateInvestmentRequest(
            $data, 
            $minAmount, 
            $maxAmount, 
            $this->currencyService->formatAmount($minAmount, 'usdt'), 
            $this->currencyService->formatAmount($maxAmount, 'usdt')
        );
        $validated = $request->validateOrFail();
        
        $amount = (float)$validated['amount'];

        $payload = [
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => 'usdt',
        ];

        $explicitKey = $idempotencyKey !== null && $idempotencyKey !== ''
            ? $idempotencyKey
            : \Core\IdempotencyKey::generateFromPayload('investment_creation', $payload);

        return $this->idempotencyService->execute('investment.create', $userId, $payload, function () use (
            $userId,
            $amount,
            $explicitKey,
            $data
        ) {
            $saga = \Core\Container::getInstance()->make(\App\Services\SagaOrchestrator::class);
            $payResult = null;
            $investmentId = null;

            $saga->addStep(
                'wallet_deduction',
                function() use ($userId, $amount, $explicitKey, &$payResult) {
                    $walletRecord = $this->db->selectOne("SELECT * FROM wallets WHERE user_id = ?", [$userId]);
                    if (!$walletRecord) {
                        $this->walletService->getOrCreateWallet($userId);
                        $walletRecord = $this->db->selectOne("SELECT * FROM wallets WHERE user_id = ?", [$userId]);
                    }
                    
                    if (!$walletRecord || (float)$walletRecord->balance_usdt < $amount) {
                        throw new \Core\Exceptions\InsufficientBalanceException('موجودی تتری کافی نیست');
                    }

                    $activeCount = $this->db->query("SELECT COUNT(*) FROM investments WHERE user_id = ? AND status = 'active'", [$userId])->fetchColumn();
                    if ($activeCount > 0) {
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
                        throw new \Core\Exceptions\BusinessException('خطا در کسر موجودی: ' . ($payResult['message'] ?? ''));
                    }
                },
                function(\Throwable $e) use ($userId, $amount, $explicitKey, &$payResult) {
                    if (!empty($payResult['success'])) {
                        $this->walletService->deposit(
                            $userId,
                            (string)$amount,
                            'usdt',
                            [
                                'type' => 'investment_refund',
                                'description' => 'بازگشت وجه به دلیل خطای ایجاد سرمایه‌گذاری',
                                'idempotency_key' => 'refund_' . $explicitKey
                            ]
                        );
                    }
                }
            )->addStep(
                'create_investment_record',
                function() use ($userId, $amount, &$payResult, &$investmentId) {
                    // ۲. ثبت سرمایه‌گذاری
                    $investmentId = $this->investmentModel->create([
                        'user_id' => $userId,
                        'amount' => $amount,
                        'current_balance' => $amount,
                        'status' => \App\Models\Investment::STATUS_ACTIVE,
                        'transaction_id' => $payResult['transaction_id'] ?? null,
                    ]);
                    
                    if (!$investmentId) {
                        throw new \Core\Exceptions\BusinessException('خطا در ثبت رکورد سرمایه‌گذاری');
                    }
                },
                function(\Throwable $e) use (&$investmentId) {
                    if ($investmentId) {
                        $this->investmentModel->update($investmentId, ['status' => 'failed']);
                    }
                }
            );

            try {
                $saga->execute();

                // 🚀 بعد از اجرای موفق Saga، رویداد به صورت async ارسال می‌شود
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
                $this->logger->error('investment_create_failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }, $explicitKey);
    }
}
