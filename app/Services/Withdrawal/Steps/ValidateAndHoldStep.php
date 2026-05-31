<?php

declare(strict_types=1);

namespace App\Services\Withdrawal\Steps;

use App\Contracts\SagaStepInterface;
use App\Contracts\WalletServiceInterface;
use App\Services\BankCardService;
use App\Services\Withdrawal\WithdrawalUserService;
use App\Exceptions\BusinessException;
use App\Models\Wallet;
use App\Contracts\LoggerInterface;

class ValidateAndHoldStep implements SagaStepInterface
{
    private WalletServiceInterface $wallet;
    private BankCardService $bankCardService;
    private WithdrawalUserService $withdrawalUserService;
    private Wallet $walletModel;
public function __construct(
        WalletServiceInterface $wallet,
        BankCardService $bankCardService,
        WithdrawalUserService $withdrawalUserService,
        Wallet $walletModel,
        LoggerInterface $logger
    ) {
        $this->wallet = $wallet;
        $this->bankCardService = $bankCardService;
        $this->withdrawalUserService = $withdrawalUserService;
        $this->walletModel = $walletModel;
        $this->logger = $logger;
    }

    public function getName(): string
    {
        return 'validate_and_hold';
    }

    public function execute(mixed $payload): mixed
    {
        $userId = $payload['user_id'];
        $amount = (string)($payload['amount'] ?? '0');
        $currency = strtolower((string)($payload['currency'] ?? 'irt'));
        $bankCardId = (int)($payload['bank_card_id'] ?? 0);
        $idempotencyKey = $payload['idempotency_key'];

        // Lock the user's wallet
        $this->walletModel->findByUserIdForUpdate($userId);
        
        // Use the business validation logic from WithdrawalUserService
        $this->withdrawalUserService->guardCanCreateWithdrawal($userId, $payload);

        if ($currency === 'irt') {
            if ($bankCardId <= 0 || !$this->bankCardService->findVerifiedCardForUser($userId, $bankCardId)) {
                throw new BusinessException('کارت بانکی معتبر یافت نشد');
            }
        }

        // کسر وجه (Hold) در کیف پول
        $withdrawResult = $this->wallet->withdraw($userId, $amount, $currency, [
            'idempotency_key' => "wth_user_req_" . $idempotencyKey,
            'card_id' => $bankCardId,
        ]);

        if (empty($withdrawResult['success'])) {
            throw new BusinessException($withdrawResult['message'] ?? 'خطا در عملیات کیف پول');
        }

        // افزودن نتیجه به payload برای استفاده در قدم بعدی
        $payload['withdraw_result'] = $withdrawResult;
        return $payload;
    }

    public function compensate(mixed $payload, mixed $result, \Throwable $originalError): void
    {
        $userId = $payload['user_id'] ?? null;
        $amount = (string)($payload['amount'] ?? '0');
        $currency = strtolower((string)($payload['currency'] ?? 'irt'));
        
        $withdrawResult = $result ?? ($payload['withdraw_result'] ?? null);

        if ($userId && !empty($withdrawResult['success']) && !empty($withdrawResult['transaction_id'])) {
            $this->logger->warning('saga.compensating.withdrawal_wallet_hold', ['user_id' => $userId]);
            $this->wallet->cancelWithdrawal((int)$userId, $amount, $currency, $withdrawResult['transaction_id']);
        }
    }
}

