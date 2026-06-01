<?php

declare(strict_types=1);

namespace App\Jobs\Withdrawal;

use App\Events\WithdrawalCreatedEvent;
use App\Events\WithdrawalEvent;
use App\Exceptions\BusinessException;
use Core\EventDispatcher;

class RequestWithdrawalUserJob
{
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Shared\IdempotencyService $idempotencyService;
    private \App\Services\KYCService $kycService;
    private \App\Contracts\WalletServiceInterface $wallet;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private \App\Services\BankCardService $bankCardService;
    private \App\Services\Withdrawal\WithdrawalQueryService $queryService;
    private \App\Models\Withdrawal $model;
    private \App\Services\FeatureFlagService $featureFlagService;
    private \Core\RateLimiter $rateLimiter;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        \App\Services\KYCService $kycService,
        \App\Contracts\WalletServiceInterface $wallet,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        \App\Services\BankCardService $bankCardService,
        \App\Services\Withdrawal\WithdrawalQueryService $queryService,
        \App\Models\Withdrawal $model,
        \App\Services\FeatureFlagService $featureFlagService,
        \Core\RateLimiter $rateLimiter
    ) {        $this->db = $db;
        $this->logger = $logger;
        $this->idempotencyService = $idempotencyService;
        $this->kycService = $kycService;
        $this->wallet = $wallet;
        $this->fraudGuard = $fraudGuard;
        $this->bankCardService = $bankCardService;
        $this->queryService = $queryService;
        $this->model = $model;
        $this->featureFlagService = $featureFlagService;
        $this->rateLimiter = $rateLimiter;
}

    public function handle(int $userId, array $payload): array
    {
        $amount = (string)($payload['amount'] ?? '0');
        $currency = strtolower((string)($payload['currency'] ?? 'irt'));
        $bankCardId = (int)($payload['bank_card_id'] ?? 0);
        $idempotencyKey = $payload['idempotency_key'] 
            ?? hash('sha256', $userId . '|' . $amount . '|' . date('YmdHi'));

        return $this->idempotencyService->execute('withdrawal.user_request', $userId, ['key' => $idempotencyKey], function() use ($userId, $payload, $amount, $currency, $bankCardId, $idempotencyKey) {
            // [SAGA REFACTOR] Removed $this->db->transaction to allow true distributed execution
                
                $saga = \Core\Container::getInstance()->make(\App\Services\SagaOrchestrator::class);
                $saga->setSaga('withdrawal.user_request', [
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'bank_card_id' => $bankCardId,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $saga->addStep(\Core\Container::getInstance()->make(\App\Services\Withdrawal\Steps\ValidateAndHoldStep::class));
                $saga->addStep(\Core\Container::getInstance()->make(\App\Services\Withdrawal\Steps\CreateRecordStep::class));

                $finalPayload = $saga->execute();

                $result = [
                    'success' => true,
                    'data' => ['withdrawal_id' => $finalPayload['withdrawal_id'] ?? 0],
                    '_withdrawal_id' => (int)($finalPayload['withdrawal_id'] ?? 0),
                ];

            // 🚀 رویداد را بعد از commit تراکنش به صورت async ارسال می‌کنیم
            if (!empty($result['success'])) {
                EventDispatcher::getInstance()->dispatchAsync(
                    WithdrawalCreatedEvent::class,
                    new WithdrawalCreatedEvent(
                        $userId,
                        $result['_withdrawal_id'],
                        (float)$amount,
                        $currency,
                        'pending'
                    )
                );
                EventDispatcher::getInstance()->dispatchAsync(
                    WithdrawalEvent::class,
                    new WithdrawalEvent([
                        'action' => 'created',
                        'user_id' => $userId,
                        'withdrawal_id' => $result['_withdrawal_id'],
                        'amount' => (float)$amount,
                        'currency' => $currency,
                        'status' => 'pending',
                    ])
                );
                unset($result['_withdrawal_id']);
            }

            return $result;
        }, $idempotencyKey);
    }
    public function guardCanCreateWithdrawal(int $userId, array $data): void
    {
        // 🛑 Kill Switch: بررسی در لایه Domain
        if (!$this->featureFlagService->isEnabled('withdrawal_flow')) {
            throw new BusinessException('سرویس برداشت وجه در حال حاضر به دلیل به‌روزرسانی یا مسائل فنی غیرفعال است.');
        }

        // 🛡️ Domain Rate Limiting
        if (!$this->rateLimiter->financial('withdrawal', $userId)) {
            throw new BusinessException('تعداد درخواست‌های برداشت شما بیش از حد مجاز است. لطفاً بعداً تلاش کنید.');
        }

        $amount = (string)($data['amount'] ?? '0');

        if (bccomp($amount, '0', 8) <= 0) {
            throw new BusinessException('مبلغ برداشت باید بیشتر از صفر باشد');
        }

        if (!$this->kycService->isApproved($userId)) {
            throw new BusinessException('احراز هویت شما تکمیل نشده است');
        }

        if ($this->queryService->hasPendingWithdrawal($userId, true)) {
            throw new BusinessException('شما یک درخواست برداشت معلق دارید');
        }

        $summary = $this->wallet->getWalletSummary($userId);
        if (empty($summary->can_withdraw_today)) {
            throw new BusinessException('سقف برداشت روزانه شما به اتمام رسیده است');
        }

        $this->checkFraudRisk($userId, $data);
    }
    protected function logSuccess(string $operation, array $context): void
    {
        $this->logger->info("payment.{$operation}.success", $context);
    }

    protected function logPaymentError(string $operation, string $error, array $context = []): void
    {
        $this->logger->error("payment.{$operation}.failed", array_merge($context, ['error' => $error]));
    }

    protected function logStart(string $operation, array $context): void
    {
        $this->logger->info("payment.{$operation}.started", $context);
    }
}
