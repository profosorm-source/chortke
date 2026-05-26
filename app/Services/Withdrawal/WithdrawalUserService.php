<?php

declare(strict_types=1);

namespace App\Services\Withdrawal;

use App\Services\Payment\PaymentBaseService;
use Core\Database;
use App\Exceptions\BusinessException;
use App\Contracts\LoggerInterface;
use App\Models\Withdrawal;
use App\Services\BankCardService;
use App\Services\KYCService;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\Wallet\WalletService;
use App\Events\WithdrawalCreatedEvent;
use Core\IdempotencyKey;
use Core\EventDispatcher;

/**
 * WithdrawalUserService
 * مسئول تمام عملیات‌های مربوط به "کاربر" در فرآیند برداشت وجه.
 */
class WithdrawalUserService extends PaymentBaseService
{
    private KYCService $kycService;
    private WalletService $wallet;
    private FraudGuardService $fraudGuard;
    private BankCardService $bankCardService;
    private WithdrawalQueryService $queryService;
    private Withdrawal $model;

    public function __construct(
        Database $db,
        KYCService $kycService,
        WalletService $wallet,
        FraudGuardService $fraudGuard,
        BankCardService $bankCardService,
        WithdrawalQueryService $queryService,
        Withdrawal $model,
        LoggerInterface $logger,
        IdempotencyKey $idempotencyKey
    ) {
        parent::__construct($logger, $idempotencyKey, $db);
        $this->kycService = $kycService;
        $this->wallet = $wallet;
        $this->fraudGuard = $fraudGuard;
        $this->bankCardService = $bankCardService;
        $this->queryService = $queryService;
        $this->model = $model;
    }

    /**
     * ثبت درخواست برداشت توسط کاربر
     */
    public function requestFromUser(int $userId, array $payload): array
    {
        $amount = (string)($payload['amount'] ?? '0');
        $currency = strtolower((string)($payload['currency'] ?? 'irt'));
        $bankCardId = (int)($payload['bank_card_id'] ?? 0);
        $idempotencyKey = $payload['idempotency_key'] 
            ?? hash('sha256', $userId . '|' . $amount . '|' . date('YmdHi'));

        return $this->idempotent('withdrawal.user_request', $userId, ['key' => $idempotencyKey], function() use ($userId, $payload, $amount, $currency, $bankCardId, $idempotencyKey) {
            return $this->db->transaction(function() use ($userId, $payload, $amount, $currency, $bankCardId, $idempotencyKey) {
                
                // قفل کردن کیف پول جهت جلوگیری از Race Condition
                $this->db->query("SELECT id FROM wallets WHERE user_id = ? FOR UPDATE", [$userId]);
                
                $this->guardCanCreateWithdrawal($userId, $payload);

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

                $withdrawal = $this->model->create([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'pending',
                    'card_id' => $bankCardId > 0 ? $bankCardId : null,
                    'transaction_id' => $withdrawResult['transaction_id'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                EventDispatcher::getInstance()->dispatch(
                    WithdrawalCreatedEvent::class,
                    new WithdrawalCreatedEvent(
                        $userId,
                        (int)$withdrawal->id,
                        (float)$amount,
                        $currency,
                        'pending'
                    )
                );

                return [
                    'success' => true,
                    'data' => ['withdrawal_id' => $withdrawal->id]
                ];
            });
        }, $idempotencyKey);
    }

    /**
     * اعتبارسنجی بیزینسی درخواست کاربر
     */
    public function guardCanCreateWithdrawal(int $userId, array $data): void
    {
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

    private function checkFraudRisk(int $userId, array $data): void
    {
        $risk = $this->fraudGuard->checkAction($userId, 'withdrawal.create', [
            'amount' => $data['amount'],
            'ip' => $data['ip'] ?? get_client_ip(),
        ]);

        if (empty($risk['allowed'])) {
            $this->logger->warning('withdrawal.fraud_blocked', ['user_id' => $userId, 'data' => $data]);
            throw new BusinessException('درخواست شما به دلایل امنیتی مسدود شد');
        }
    }
}