<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\Wallet;
use App\Models\Transaction;
use Core\Database;

use App\Services\LedgerService;
use App\Services\SettingService;
use App\Contracts\WalletServiceInterface;

use App\Traits\WalletHelperTrait;

class WalletService extends \App\Services\BaseService implements WalletServiceInterface
{
    

    private Wallet      $walletModel;
    private Transaction $transactionModel;
    private Database    $db;
    private ?LedgerService $ledgerService = null;
    
    private DistributedLockService $lockService;
    private SettingService $settingService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private \Core\EventDispatcher $events;
    private ?\App\Services\OutboxService $outbox;
    
    
    
    private array $supportedCurrencies = ['irt', 'usdt'];

    public function __construct(
        Database $db,
        \App\Models\Wallet $walletModel,
        \App\Models\Transaction $transactionModel,
        \Core\IdempotencyKey $idempotencyKey,
        LoggerInterface $logger,
        
        LedgerService $ledgerService,
        DistributedLockService $lockService,
        SettingService $settingService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        \Core\EventDispatcher $events,
        ?\App\Services\OutboxService $outbox = null,
        
        
        
    ) {
        parent::__construct($logger, $idempotencyKey);
        $this->db = $db;
        $this->walletModel = $walletModel;
        $this->transactionModel = $transactionModel;
        
        $this->ledgerService = $ledgerService;
        $this->lockService = $lockService;
        $this->settingService = $settingService;
        $this->fraudGuard = $fraudGuard;
        $this->events = $events;
        $this->outbox = $outbox;
        
        
        

        // Load supported currencies from config
        $configuredCurrencies = $settingService->get('wallet_supported_currencies');
        if (is_array($configuredCurrencies) && !empty($configuredCurrencies)) {
            $this->supportedCurrencies = array_map('strtolower', $configuredCurrencies);
        } elseif (function_exists('config')) {
            $configCurrencies = config('wallet.supported_currencies', ['irt', 'usdt']);
            if (is_array($configCurrencies)) {
                $this->supportedCurrencies = array_map('strtolower', $configCurrencies);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Public API - Queries & General Helpers
    // ─────────────────────────────────────────────────────────────

    public function getOrCreateWallet(int $userId): ?object
    {
        $sql = "INSERT IGNORE INTO wallets (user_id, created_at, updated_at)
                VALUES (:user_id, NOW(), NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        return $this->walletModel->findByUserId($userId);
    }

    public function getWalletBalances(int $userId): array
    {
        $wallet = $this->getOrCreateWallet($userId);
        if (!$wallet) {
            return [];
        }

        $irtBalance = (string)($wallet->balance_irt ?? '0');
        $irtLocked = (string)($wallet->locked_irt ?? '0');
        $irtAvailable = bcsub($irtBalance, $irtLocked, 4);
        if (bccomp($irtAvailable, '0', 4) < 0) {
            $this->logger->critical('wallet.balance_locked_inconsistency', [
                'user_id' => $userId,
                'balance' => $irtBalance,
                'locked' => $irtLocked,
                'currency' => 'IRT',
            ]);

            $this->walletModel->freezeWallet($userId);
            throw new \RuntimeException('کیف پول شما به دلیل مشکل سیستمی موقتاً مسدود شده است');
        }

        $usdtBalance = (string)($wallet->balance_usdt ?? '0');
        $usdtLocked = (string)($wallet->locked_usdt ?? '0');
        $usdtAvailable = bcsub($usdtBalance, $usdtLocked, 8);
        if (bccomp($usdtAvailable, '0', 8) < 0) {
            $this->logger->critical('wallet.balance_locked_inconsistency', [
                'user_id' => $userId,
                'balance' => $usdtBalance,
                'locked' => $usdtLocked,
                'currency' => 'USDT',
            ]);

            $this->walletModel->freezeWallet($userId);
            throw new \RuntimeException('کیف پول شما به دلیل مشکل سیستمی موقتاً مسدود شده است');
        }

        return [
            'irt_balance'        => $irtBalance,
            'irt_locked'         => $irtLocked,
            'irt_available'      => $irtAvailable,
            'usdt_balance'       => $usdtBalance,
            'usdt_locked'        => $usdtLocked,
            'usdt_available'     => $usdtAvailable,
            'last_withdrawal_at' => $wallet->last_withdrawal_at ?? null,
        ];
    }

    private function assertWalletActive(int $userId): void
    {
        if ($this->walletModel->isFrozen($userId)) {
            throw new \RuntimeException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
        }
    }

    private function getScale(string $currency): int
    {
        return strtolower($currency) === 'usdt' ? 8 : 4;
    }

    private function validateCurrency(string $currency): void
    {
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            $supportedList = implode("'، '", $this->supportedCurrencies);
            throw new \InvalidArgumentException("ارز '{$currency}' پشتیبانی نمی‌شود. فقط '{$supportedList}' معتبر است.");
        }
    }

    private function balanceField(string $currency): string
    {
        return $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
    }

    private function standardizeResponse(array $response): array
    {
        return [
            'success'        => (bool)($response['success'] ?? false),
            'transaction_id' => $response['transaction_id'] ?? null,
            'message'        => $response['message'] ?? '',
            'new_balance'    => isset($response['new_balance']) ? (string)$response['new_balance'] : null,
            'amount'         => isset($response['amount']) ? (string)$response['amount'] : null,
            'currency'       => $response['currency'] ?? null,
            'status'         => $response['status'] ?? null,
            'error'          => $response['error'] ?? null,
            'balance_before' => isset($response['balance_before']) ? (string)$response['balance_before'] : null,
            'balance_after'  => isset($response['balance_after']) ? (string)$response['balance_after'] : null,
        ];
    }


    private function ledger(): LedgerService
    {
        return $this->ledgerService;
    }

    // ─────────────────────────────────────────────────────────────
    // Mutations & Transaction Logic
    // ─────────────────────────────────────────────────────────────

    public function deposit(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateDepositInput($userId, $amount, $currency, $metadata);

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "DEP_{$requestId}";

        $uniqueParts = array_filter([
            $userId,
            'deposit',
            $amount,
            $currency,
            $metadata['gateway_transaction_id'] ?? '',
            $metadata['ref_id']                 ?? '',
            $metadata['deposit_id']             ?? '',
            $metadata['tracking_code']          ?? '',
            $ipAddress,
        ], fn($v) => $v !== '');

        if (empty($metadata['gateway_transaction_id']) && empty($metadata['ref_id']) && empty($metadata['deposit_id']) && empty($metadata['tracking_code'])) {
            $uniqueParts[] = uniqid('rand_', true);
            $uniqueParts[] = (string)microtime(true);
        }

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', $uniqueParts));

        try {
            return $this->lockService->synchronized("wallet:mut:{$userId}", function() use ($userId, $amount, $currency, $metadata, $idempotencyKey, $requestId, $ipAddress, $deviceFingerprint, $logId) {
                return $this->processDepositTransaction(
                    $userId, $amount, $currency, $metadata, $idempotencyKey,
                    $requestId, $ipAddress, $deviceFingerprint, $logId
                );
            }, 15, 10);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Failed to acquire lock')) {
                $this->logger->warning('wallet.lock_timeout', ['user_id' => $userId, 'action' => 'deposit', 'error' => $e->getMessage()]);
                return ['success' => false, 'message' => 'سیستم در حال حاضر شلوغ است، لطفاً لحظاتی بعد تلاش کنید'];
            }
            throw $e;
        }
    }

    public function depositInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateDepositInput($userId, $amount, $currency, $metadata);

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "DEP_TX_{$requestId}";

        $uniqueParts = array_filter([
            $userId,
            'deposit_tx',
            $amount,
            $currency,
            $metadata['gateway_transaction_id'] ?? '',
            $metadata['ref_id']                 ?? '',
            $metadata['deposit_id']             ?? '',
            $metadata['tracking_code']          ?? '',
            $ipAddress,
        ], fn($v) => $v !== '');

        if (empty($metadata['gateway_transaction_id']) && empty($metadata['ref_id']) && empty($metadata['deposit_id']) && empty($metadata['tracking_code'])) {
            $uniqueParts[] = uniqid('rand_', true);
            $uniqueParts[] = (string)microtime(true);
        }

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', $uniqueParts));

        return $this->processDepositTransaction(
            $userId, $amount, $currency, $metadata, $idempotencyKey,
            $requestId, $ipAddress, $deviceFingerprint, $logId
        );
    }

    private function validateDepositInput(int $userId, string $amount, string $currency, array $metadata): void
    {
        $this->validateCurrency($currency);

        $refundTypes = ['withdrawal_refund', 'refund', 'deposit_refund', 'scheduled_payment_refund'];
        if (!in_array($metadata['type'] ?? 'deposit', $refundTypes, true)) {
            $this->assertWalletActive($userId);
        }

        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $type = $metadata['type'] ?? 'deposit';
        $bypassMinDepositTypes = [
            'social_task_reward', 'social_task_refund', 'dispute_refund', 'dispute_release',
            'dispute_partial_release', 'vitrine_refund', 'vitrine_sale', 'influencer_order_payment',
            'influencer_escrow', 'social_task_escrow', 'vitrine_escrow', 'withdrawal_refund',
            'refund', 'deposit_refund', 'scheduled_payment_refund'
        ];

        if (!in_array($type, $bypassMinDepositTypes, true)) {
            $minAmount = ($currency === 'usdt')
                ? (string)$this->settingService->get('min_deposit_usdt', '1.0')
                : (string)$this->settingService->get('min_deposit_irt', '1000.0');
            if (bccomp($amount, $minAmount, $this->getScale($currency)) < 0) {
                throw new \InvalidArgumentException("حداقل مبلغ واریز {$minAmount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " است");
            }
        }
    }

    private function processDepositTransaction(
        int $userId, string $amount, string $currency, array $metadata, string $idempotencyKey,
        string $requestId, string $ipAddress, string $deviceFingerprint, string $logId
    ): array {
        $idempotencyService = $this->idempotencyKey;
        $startedTransaction = !$this->db->inTransaction();

        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            $check = $idempotencyService->check($idempotencyKey, $userId, 'wallet_deposit', [
                'amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress,
            ]);

            if ($check['is_duplicate']) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $this->logger->warning('wallet.deposit.duplicate', [
                    'channel' => 'wallet',
                    'log_id' => $logId,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return $this->standardizeResponse($check['result']);
            }

            $wallet = $this->walletModel->findByUserId($userId);
            if (!$wallet) {
                throw new \RuntimeException('خطا در دریافت wallet');
            }
            $refundTypes = ['withdrawal_refund', 'refund', 'deposit_refund', 'scheduled_payment_refund'];
            if (!in_array($metadata['type'] ?? 'deposit', $refundTypes, true)) {
                if ((bool)($wallet->is_frozen ?? 0)) {
                    throw new \RuntimeException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
                }
            }

            $balanceField  = $this->balanceField($currency);
            $balanceBefore = (string)($wallet->$balanceField ?? '0');
            $scale         = $this->getScale($currency);
            $balanceAfter  = bcadd($balanceBefore, $amount, $scale);

            if (!$this->walletModel->updateBalance($userId, $amount, $currency)) {
                throw new \RuntimeException('خطا در بروزرسانی موجودی');
            }

            $transaction = $this->transactionModel->create([
                'user_id'                => $userId,
                'type'                   => $metadata['type'] ?? 'deposit',
                'currency'               => $currency,
                'amount'                 => $amount,
                'balance_before'         => $balanceBefore,
                'balance_after'          => $balanceAfter,
                'status'                 => 'completed',
                'description'            => $metadata['description'] ?? 'واریز وجه',
                'gateway'                => $metadata['gateway']                ?? null,
                'gateway_transaction_id' => $metadata['gateway_transaction_id'] ?? null,
                'ref_id'                 => $metadata['ref_id']                 ?? null,
                'ref_type'               => $metadata['ref_type']               ?? null,
                'request_id'             => $requestId,
                'ip_address'             => $ipAddress,
                'device_fingerprint'     => $deviceFingerprint,
                'idempotency_key'        => $idempotencyKey,
                'metadata'               => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip'     => $ipAddress,
                    'device'     => $deviceFingerprint,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'timestamp'  => date('Y-m-d H:i:s'),
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \RuntimeException('خطا در ثبت تراکنش');
            }

            $this->ledger()->recordDoubleEntry(
                $transaction->transaction_id,
                "wallet:{$userId}",
                'external_payment',
                $amount,
                $currency,
                $metadata['description'] ?? 'واریز وجه',
                [
                    'gateway' => $metadata['gateway'] ?? null,
                    'ref_id' => $metadata['ref_id'] ?? null,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ]
            );

            $result = $this->standardizeResponse([
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'message'        => 'واریز با موفقیت انجام شد',
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'completed',
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
            ]);

            $this->outbox?->record('wallet_transaction', (string)$userId, 'wallet.deposit.completed', [
                'user_id' => $userId,
                'transaction_id' => $transaction->transaction_id ?? ($transaction->id ?? null),
                'result' => $result
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }

            $idempotencyService->complete($idempotencyKey, $result, $userId);

            $this->logger->activity(
                'wallet.deposit',
                "واریز {$amount} " . ($currency === 'usdt' ? 'USDT' : 'تومان'),
                $userId,
                [
                    'channel' => 'wallet',
                    'transaction_id' => $transaction->transaction_id,
                ]
            );

            $this->logger->info('wallet.deposit.success', [
                'channel' => 'wallet',
                'log_id' => $logId,
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            return $result;

        } catch (\InvalidArgumentException $e) {
            $failResult = $this->standardizeResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'validation_error',
                'message' => $e->getMessage(),
            ]);

            $idempotencyService->fail($idempotencyKey, $failResult, $userId);
            throw $e;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $failResult = $this->standardizeResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'runtime_error',
                'message' => 'خطای سیستمی در فرآیند واریز',
            ]);

            $idempotencyService->fail($idempotencyKey, $failResult, $userId);
            throw $e;
        }
    }

    public function withdraw(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateWithdrawInput($userId, $amount, $currency, $metadata);

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "WTH_{$requestId}";

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', [
            $userId, 'withdraw', $amount, $currency,
            $metadata['card_id']        ?? '',
            $metadata['wallet_address'] ?? '',
        ]));

        $idempotencyService = $this->idempotencyKey;
        $check = $idempotencyService->check($idempotencyKey, $userId, 'wallet_withdraw', [
            'amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress,
        ]);

        if ($check['is_duplicate']) {
            return $this->standardizeResponse($check['result']);
        }

        try {
            return $this->lockService->synchronized("wallet:mut:{$userId}", function() use ($userId, $amount, $currency, $metadata, $idempotencyKey, $requestId, $ipAddress, $deviceFingerprint, $logId) {
                return $this->processWithdrawTransaction(
                    $userId, $amount, $currency, $metadata, $idempotencyKey,
                    $requestId, $ipAddress, $deviceFingerprint, $logId
                );
            }, 15, 10);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Failed to acquire lock')) {
                $this->logger->warning('wallet.lock_timeout', ['user_id' => $userId, 'action' => 'withdraw', 'error' => $e->getMessage()]);
                return ['success' => false, 'message' => 'سیستم در حال حاضر شلوغ است، لطفاً لحظاتی بعد تلاش کنید'];
            }
            throw $e;
        }
    }

    public function withdrawInTransaction(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateCurrency($currency);
        $this->assertWalletActive($userId);

        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('withdrawInTransaction() requires an active database transaction. Use withdraw() instead.');
        }

        try {
            $wallet = $this->walletModel->findByUserId($userId);
            if (!$wallet) {
                throw new \Core\Exceptions\EntityNotFoundException('خطا در دریافت کیف پول');
            }
            if ((bool)($wallet->is_frozen ?? 0)) {
                throw new \Core\Exceptions\InvalidStateException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
            }

            $balanceField = $this->balanceField($currency);
            $currentBalance = (string)($wallet->$balanceField ?? '0');
            $scale = $this->getScale($currency);

            if (bccomp($currentBalance, $amount, $scale) < 0) {
                throw new \Core\Exceptions\InsufficientBalanceException("موجودی کافی نیست (موجودی فعلی: {$currentBalance})");
            }

            $balanceBefore = $currentBalance;
            $balanceAfter = bcsub($balanceBefore, $amount, $scale);

            if (!$this->walletModel->lockBalance($userId, $amount, $currency)) {
                throw new \RuntimeException('خطا در بروزرسانی موجودی');
            }

            if (!$this->walletModel->updateLastWithdrawal($userId)) {
                throw new \RuntimeException('خطا در بروزرسانی زمان آخرین برداشت');
            }

            $requestId = $metadata['request_id'] ?? get_request_id();
            $ipAddress = $metadata['ip_address'] ?? get_client_ip();
            $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();

            $transaction = $this->transactionModel->create([
                'user_id'            => $userId,
                'type'               => $metadata['type'] ?? 'internal_withdraw',
                'currency'           => $currency,
                'amount'             => $amount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'completed',
                'description'        => $metadata['description'] ?? 'برداشت داخلی',
                'ref_id'             => $metadata['ref_id']             ?? null,
                'ref_type'           => $metadata['ref_type']           ?? null,
                'request_id'         => $requestId,
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'metadata'           => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip'     => $ipAddress,
                    'device'     => $deviceFingerprint,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'timestamp'  => date('Y-m-d H:i:s'),
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \RuntimeException('خطا در ثبت تراکنش');
            }

            $this->ledger()->recordDoubleEntry(
                $transaction->transaction_id,
                $metadata['debit_account'] ?? 'platform_cash',
                "wallet:{$userId}",
                $amount,
                $currency,
                $metadata['description'] ?? 'برداشت داخلی',
                [
                    'type' => $metadata['type'] ?? 'internal_withdraw',
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                ]
            );

            $this->dispatchWalletEvent('wallet.withdraw.internal_completed', $userId, $transaction->transaction_id ?? ($transaction->id ?? null), [
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed',
            ]);

            return [
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'completed',
            ];

        } catch (\Throwable $e) {
            $this->logger->error('wallet.withdraw_in_transaction.failed', [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function validateWithdrawInput(int $userId, string $amount, string $currency, array $metadata): void
    {
        $this->validateCurrency($currency);
        $this->assertWalletActive($userId);

        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $minAmount = ($currency === 'usdt')
            ? (string)$this->settingService->get('min_withdraw_usdt', '5.0')
            : (string)$this->settingService->get('min_withdraw_irt', '10000.0');
        if (bccomp($amount, $minAmount, $this->getScale($currency)) < 0) {
            throw new \InvalidArgumentException("حداقل مبلغ برداشت {$minAmount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " است");
        }
    }

    private function processWithdrawTransaction(
        int $userId, string $amount, string $currency, array $metadata, string $idempotencyKey,
        string $requestId, string $ipAddress, string $deviceFingerprint, string $logId
    ): array {
        $idempotencyService = $this->idempotencyKey;
        $startedTransaction = !$this->db->inTransaction();

        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            $wallet = $this->walletModel->findByUserId($userId);
            if (!$wallet) {
                throw new \Core\Exceptions\EntityNotFoundException('خطا در دریافت کیف پول');
            }
            if ((bool)($wallet->is_frozen ?? 0)) {
                throw new \Core\Exceptions\InvalidStateException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
            }

            $balanceField   = $this->balanceField($currency);
            $currentBalance = (string)($wallet->$balanceField ?? '0');
            $scale          = $this->getScale($currency);

            if (bccomp($currentBalance, $amount, $scale) < 0) {
                throw new \Core\Exceptions\InsufficientBalanceException("موجودی کافی نیست (موجودی فعلی: {$currentBalance})");
            }

            $balanceBefore = $currentBalance;
            $balanceAfter  = bcsub($balanceBefore, $amount, $scale);

            if (!$this->walletModel->lockBalance($userId, $amount, $currency)) {
                throw new \RuntimeException('خطا در قفل کردن موجودی');
            }

            if (!$this->walletModel->updateLastWithdrawal($userId)) {
                throw new \RuntimeException('خطا در بروزرسانی زمان آخرین برداشت');
            }

            $transaction = $this->transactionModel->create([
                'user_id'            => $userId,
                'type'               => 'withdraw',
                'currency'           => $currency,
                'amount'             => $amount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'pending',
                'description'        => $metadata['description'] ?? 'برداشت وجه',
                'ref_id'             => $metadata['ref_id']             ?? null,
                'ref_type'           => $metadata['ref_type']           ?? null,
                'request_id'         => $requestId,
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'idempotency_key'    => $idempotencyKey,
                'metadata'           => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip'     => $ipAddress,
                    'device'     => $deviceFingerprint,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \RuntimeException('خطا در ثبت تراکنش');
            }

            $result = $this->standardizeResponse([
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'message'        => 'درخواست برداشت ثبت شد و منتظر تایید است',
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'pending',
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
            ]);

            $this->dispatchWalletEvent('wallet.withdraw.requested', $userId, $transaction->transaction_id ?? ($transaction->id ?? null), $result);

            if ($startedTransaction) {
                $this->db->commit();
            }

            $idempotencyService->complete($idempotencyKey, $result, $userId);

            $this->logger->info('wallet.withdraw.success', [
                'channel' => 'wallet',
                'log_id' => $logId,
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            return $result;

        } catch (\InvalidArgumentException $e) {
            $failResult = $this->standardizeResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'validation_error',
                'message' => $e->getMessage(),
            ]);

            $idempotencyService->fail($idempotencyKey, $failResult, $userId);
            throw $e;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            $failResult = $this->standardizeResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'runtime_error',
                'message' => 'خطای سیستمی در فرآیند برداشت',
            ]);

            $idempotencyService->fail($idempotencyKey, $failResult, $userId);
            throw $e;
        }
    }

    public function pay(int $userId, string $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateCurrency($currency);
        $this->assertWalletActive($userId);

        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ پرداخت باید بیشتر از صفر باشد');
        }

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "PAY_{$requestId}";

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', [
            $userId, 'pay', $amount, $currency,
            $metadata['type'] ?? 'payment',
            $metadata['ref_id'] ?? '',
            $metadata['ref_type'] ?? '',
        ]));

        $idempotencyService = $this->idempotencyKey;
        $check = $idempotencyService->check($idempotencyKey, $userId, 'wallet_pay', [
            'amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress,
        ]);

        if ($check['is_duplicate']) {
            return $this->standardizeResponse($check['result']);
        }

        try {
            return $this->lockService->synchronized("wallet:mut:{$userId}", function() use (
                $userId, $amount, $currency, $metadata, $idempotencyKey,
                $requestId, $ipAddress, $deviceFingerprint, $logId, $idempotencyService
            ) {
                $startedTransaction = !$this->db->inTransaction();

                try {
                    if ($startedTransaction) {
                        $this->db->beginTransaction();
                    }

                    $wallet = $this->walletModel->findByUserId($userId);
                    if (!$wallet) {
                        throw new \Core\Exceptions\EntityNotFoundException('خطا در دریافت کیف پول');
                    }
                    if ((bool)($wallet->is_frozen ?? 0)) {
                        throw new \Core\Exceptions\InvalidStateException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
                    }

                    $balanceField   = $this->balanceField($currency);
                    $currentBalance = (string)($wallet->$balanceField ?? '0');
                    $scale          = $this->getScale($currency);

                    if (bccomp($currentBalance, $amount, $scale) < 0) {
                        throw new \Core\Exceptions\InsufficientBalanceException("موجودی کافی نیست (موجودی فعلی: {$currentBalance})");
                    }

                    $balanceBefore = $currentBalance;
                    $balanceAfter  = bcsub($balanceBefore, $amount, $scale);

                    $negativeAmount = bcmul($amount, '-1', $scale);
                    if (!$this->walletModel->updateBalance($userId, $negativeAmount, $currency)) {
                        throw new \RuntimeException('خطا در کسر موجودی');
                    }

                    $transaction = $this->transactionModel->create([
                        'user_id'            => $userId,
                        'type'               => $metadata['type'] ?? 'payment',
                        'currency'           => $currency,
                        'amount'             => $negativeAmount,
                        'balance_before'     => $balanceBefore,
                        'balance_after'      => $balanceAfter,
                        'status'             => 'completed',
                        'description'        => $metadata['description'] ?? 'پرداخت هزینه',
                        'ref_id'             => $metadata['ref_id']             ?? null,
                        'ref_type'           => $metadata['ref_type']           ?? null,
                        'request_id'         => $requestId,
                        'ip_address'         => $ipAddress,
                        'device_fingerprint' => $deviceFingerprint,
                        'idempotency_key'    => $idempotencyKey,
                        'metadata'           => json_encode(array_merge($metadata, [
                            'request_id' => $requestId, 'ip'     => $ipAddress,
                            'device'     => $deviceFingerprint,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]), JSON_UNESCAPED_UNICODE),
                    ]);

                    if (!$transaction) {
                        throw new \RuntimeException('خطا در ثبت تراکنش پرداخت');
                    }

                    $this->ledger()->recordDoubleEntry(
                        $transaction->transaction_id,
                        "platform_revenue",
                        "wallet:{$userId}",
                        $amount,
                        $currency,
                        $metadata['description'] ?? 'پرداخت هزینه',
                        [
                            'type' => $metadata['type'] ?? 'payment',
                            'balance_before' => $balanceBefore,
                            'balance_after' => $balanceAfter,
                        ]
                    );

                    $result = $this->standardizeResponse([
                        'success'        => true,
                        'transaction_id' => $transaction->transaction_id,
                        'message'        => 'پرداخت با موفقیت انجام شد',
                        'new_balance'    => $balanceAfter,
                        'amount'         => $amount,
                        'currency'       => $currency,
                        'status'         => 'completed',
                        'balance_before' => $balanceBefore,
                        'balance_after'  => $balanceAfter,
                    ]);

                    $this->outbox?->record('wallet_transaction', (string)$userId, 'wallet.payment.completed', [
                'user_id' => $userId,
                'transaction_id' => $transaction->transaction_id ?? ($transaction->id ?? null),
                'result' => $result
            ]);

                    if ($startedTransaction) {
                        $this->db->commit();
                    }

                    $idempotencyService->complete($idempotencyKey, $result, $userId);

                    return $result;

                } catch (\Throwable $e) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }

                    $failResult = $this->standardizeResponse([
                        'success' => false,
                        'error' => $e->getMessage(),
                        'type' => 'runtime_error',
                        'message' => 'خطا در فرآیند پرداخت: ' . $e->getMessage(),
                    ]);

                    $idempotencyService->fail($idempotencyKey, $failResult, $userId);
                    throw $e;
                }
            }, 15, 10);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Failed to acquire lock')) {
                $this->logger->warning('wallet.lock_timeout', ['user_id' => $userId, 'action' => 'pay', 'error' => $e->getMessage()]);
                return ['success' => false, 'message' => 'سیستم در حال حاضر شلوغ است، لطفاً لحظاتی بعد تلاش کنید'];
            }
            throw $e;
        }
    }

    public function hasBalance(int $userId, string $amount, string $currency = 'irt'): bool
    {
        $currency = strtolower($currency);
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            return false;
        }
        $balance = $this->walletModel->getBalance($userId, $currency);
        return bccomp($balance, $amount, $this->getScale($currency)) >= 0;
    }

    public function completeWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool
    {
        try {
            return $this->lockService->synchronized("wallet:mut:{$userId}", function() use ($userId, $amount, $currency, $transactionId) {
                $this->assertWalletActive($userId);

                $startedTransaction = !$this->db->inTransaction();
                try {
                    if ($startedTransaction) {
                        $this->db->beginTransaction();
                    }

                    $wallet = $this->walletModel->findByUserId($userId);
                    if (!$wallet) {
                        throw new \RuntimeException("کیف پول کاربر یافت نشد.");
                    }

                    if ($transactionId) {
                        $transaction = $this->transactionModel->findByTransactionId($transactionId);
                        if ($transaction) {
                            if ($transaction->status === 'completed') {
                                if ($startedTransaction) { $this->db->commit(); }
                                return true;
                            }

                            if ($transaction->type === 'withdraw') {
                                if (!$this->walletModel->deductLocked($userId, $amount, $currency)) {
                                    throw new \RuntimeException('خطا در کسر موجودی قفل‌شده از کیف پول');
                                }

                                $this->ledger()->recordDoubleEntry(
                                    $transactionId,
                                    'platform_cash',
                                    'withdrawal_pending',
                                    $amount,
                                    $currency,
                                    'Withdrawal completed',
                                    [
                                        'user_id' => $userId,
                                        'balance_snapshot' => 'Locked balance deducted'
                                    ]
                                );
                            }
                        }

                        $this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'completed');
                    }

                    if ($startedTransaction) {
                        $this->db->commit();
                    }
                    return true;

                } catch (\Throwable $e) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error('wallet.complete_withdrawal.failed', [
                        'channel' => 'wallet',
                        'user_id' => $userId,
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage(),
                    ]);
                    return false;
                }
            }, 15, 10);
        } catch (\RuntimeException $e) {
            $this->logger->warning('wallet.complete_withdrawal.lock_timeout', [
                'channel' => 'wallet',
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function cancelWithdrawal(int $userId, string $amount, string $currency, ?string $transactionId): bool
    {
        try {
            return $this->lockService->synchronized("wallet:mut:{$userId}", function() use ($userId, $amount, $currency, $transactionId) {
                $startedTransaction = !$this->db->inTransaction();

                try {
                    if ($startedTransaction) {
                        $this->db->beginTransaction();
                    }

                    $wallet = $this->walletModel->findByUserId($userId);
                    if (!$wallet) {
                        throw new \RuntimeException("کیف پول کاربر یافت نشد.");
                    }

                    if ($transactionId) {
                        $transaction = $this->transactionModel->findByTransactionId($transactionId);
                        if ($transaction) {
                            if ($transaction->status === 'cancelled') {
                                if ($startedTransaction) { $this->db->commit(); }
                                return true;
                            }
                        }
                    }

                    if (!$this->walletModel->releaseLocked($userId, $amount, $currency)) {
                        throw new \RuntimeException('خطا در بازگرداندن موجودی قفل‌شده به کیف پول');
                    }

                    if ($transactionId) {
                        $this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'cancelled');

                        $result = $this->depositInTransaction($userId, $amount, $currency, [
                            'type'               => 'withdrawal_refund',
                            'description'        => 'بازگشت وجه برداشت لغو شده',
                            'ref_type'           => 'withdraw',
                            'ref_id'             => $transactionId,
                            'idempotency_key'    => 'withdraw_cancel_' . ($transactionId ?? uniqid()),
                        ]);

                        if (!$result['success']) {
                            throw new \RuntimeException('خطا در افزایش اعتبار فال‌بک در لغو برداشت: ' . ($result['message'] ?? 'خطای ناشناخته'));
                        }
                    }

                    if ($transactionId) {
                        $this->transactionModel->recordStatusChange(
                            $transactionId,
                            'cancelled',
                            'Withdrawal cancelled and funds returned',
                            null,
                            [
                                'refund_transaction_id' => $transactionId,
                                'ip_address' => $this->clientIp()
                            ]
                        );
                    }

                    if ($startedTransaction) {
                        $this->db->commit();
                    }
                    return true;

                } catch (\Throwable $e) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error('wallet.cancel_withdrawal.failed', [
                        'channel' => 'wallet',
                        'user_id' => $userId,
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage(),
                    ]);
                    return false;
                }
            }, 15, 10);
        } catch (\RuntimeException $e) {
            $this->logger->warning('wallet.cancel_withdrawal.lock_timeout', [
                'channel' => 'wallet',
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function canWithdraw(int $userId, string $amount, string $currency = 'irt'): array
    {
        $result = ['can_withdraw' => false, 'message' => ''];
        $scale  = $this->getScale($currency);

        $balance = $this->db->inTransaction()
            ? $this->walletModel->getBalanceForUpdate($userId, $currency)
            : $this->walletModel->getBalance($userId, $currency);
        if (bccomp($balance, $amount, $scale) < 0) {
            $result['message'] = 'موجودی کافی نیست';
            return $result;
        }

        if (!$this->walletModel->canWithdrawToday($userId)) {
            $result['message'] = 'شما امروز یکبار برداشت کرده‌اید';
            return $result;
        }

        $minWithdrawal = ($currency === 'usdt')
            ? (string)$this->settingService->get('min_withdraw_usdt', '5.0')
            : (string)$this->settingService->get('min_withdraw_irt', '10000.0');
        if (bccomp($amount, $minWithdrawal, $scale) < 0) {
            $result['message'] = 'حداقل مبلغ برداشت ' . number_format((float)$minWithdrawal) . ' ' . ($currency === 'usdt' ? 'USDT' : 'تومان') . ' است';
            return $result;
        }

        $result['can_withdraw'] = true;
        return $result;
    }

    public function getWalletSummary(int $userId): object
    {
        $wallet = $this->getOrCreateWallet($userId);
        $stats  = $this->transactionModel->getUserStats($userId);

        $totalIrt = bcadd((string)($wallet->balance_irt ?? '0'), (string)($wallet->locked_irt ?? '0'), 4);
        $totalUsdt = bcadd((string)($wallet->balance_usdt ?? '0'), (string)($wallet->locked_usdt ?? '0'), 8);

        return (object)[
            'balance_irt'        => (string)($wallet->balance_irt ?? '0'),
            'balance_usdt'       => (string)($wallet->balance_usdt ?? '0'),
            'locked_irt'         => (string)($wallet->locked_irt ?? '0'),
            'locked_usdt'        => (string)($wallet->locked_usdt ?? '0'),
            'total_irt'          => $totalIrt,
            'total_usdt'         => $totalUsdt,
            'is_frozen'          => (bool)($wallet->is_frozen ?? 0),
            'last_withdrawal_at' => $wallet->last_withdrawal_at,
            'can_withdraw_today' => $this->walletModel->canWithdrawToday($userId),
            'stats'              => $stats,
        ];
    }

    public function transfer(int $fromUserId, int $toUserId, string $amount, string $currency = 'irt', string $description = ''): ?object
    {
        $risk = $this->fraudGuard->checkAction($fromUserId, 'wallet.transfer', [
            'to_user_id' => $toUserId,
            'amount'     => $amount,
            'currency'   => $currency
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('wallet.transfer_blocked_by_fraud_guard', [
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
                'amount'       => $amount,
                'reason'       => $risk['reason']
            ]);
            throw new \RuntimeException('انتقال وجه به دلایل امنیتی مسدود گردید. دلیل: ' . ($risk['reason'] === 'velocity_limit' ? 'تجاوز از سقف جابجایی روزانه' : $risk['reason']));
        }

        $this->assertWalletActive($fromUserId);
        $this->assertWalletActive($toUserId);

        if (!is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        if ($fromUserId === $toUserId) {
            throw new \InvalidArgumentException('نمی‌توانید به خودتان انتقال دهید');
        }

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            $firstId  = min($fromUserId, $toUserId);
            $secondId = max($fromUserId, $toUserId);

            $firstWallet  = $this->walletModel->findByUserId($firstId);
            $secondWallet = $this->walletModel->findByUserId($secondId);

            if (!$firstWallet || !$secondWallet) {
                throw new \RuntimeException('کیف پول یافت نشد');
            }

            if ((bool)($firstWallet->is_frozen ?? 0) || (bool)($secondWallet->is_frozen ?? 0)) {
                throw new \RuntimeException('کیف پول یکی از کاربران مسدود شده است');
            }

            $fromWallet = ($firstId === $fromUserId) ? $firstWallet : $secondWallet;
            $toWallet   = ($firstId === $toUserId)   ? $firstWallet : $secondWallet;

            $balanceField    = $this->balanceField($currency);
            $fromBalance     = (string)($fromWallet->$balanceField ?? '0');
            $toBalanceBefore = (string)($toWallet->$balanceField ?? '0');
            $scale           = $this->getScale($currency);

            if (bccomp($fromBalance, $amount, $scale) < 0) {
                throw new \RuntimeException('موجودی کافی نیست');
            }

            $negativeAmount = bcmul($amount, '-1', $scale);
            $this->walletModel->updateBalance($fromUserId, $negativeAmount, $currency);
            $this->walletModel->updateBalance($toUserId, $amount, $currency);

            $ipAddress = function_exists('get_client_ip') ? get_client_ip() : '127.0.0.1';
            $deviceFingerprint = function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : 'system_internal';

            $fromTransaction = $this->transactionModel->create([
                'user_id'            => $fromUserId,
                'type'               => 'transfer',
                'currency'           => $currency,
                'amount'             => $negativeAmount,
                'balance_before'     => $fromBalance,
                'balance_after'      => bcsub($fromBalance, $amount, $scale),
                'status'             => 'completed',
                'description'        => $description ?: "انتقال به کاربر {$toUserId}",
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'metadata'           => json_encode(['to_user_id' => $toUserId]),
            ]);

            $transaction = $this->transactionModel->create([
                'user_id'            => $toUserId,
                'type'               => 'transfer',
                'currency'           => $currency,
                'amount'             => $amount,
                'balance_before'     => $toBalanceBefore,
                'balance_after'      => bcadd($toBalanceBefore, $amount, $scale),
                'status'             => 'completed',
                'description'        => $description ?: "دریافت از کاربر {$fromUserId}",
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'metadata'           => json_encode(['from_user_id' => $fromUserId]),
            ]);

            if ($fromTransaction && $transaction) {
                $this->ledger()->recordDoubleEntry(
                    $fromTransaction->transaction_id,
                    "wallet:{$toUserId}",
                    "wallet:{$fromUserId}",
                    $amount,
                    $currency,
                    $description ?: "انتقال وجه از {$fromUserId} به {$toUserId}",
                    [
                        'from_user_id' => $fromUserId,
                        'to_user_id' => $toUserId,
                        'from_balance_before' => $fromBalance,
                        'to_balance_before' => $toBalanceBefore,
                    ]
                );

                $this->outbox?->record('wallet_transaction', (string)$fromUserId, 'wallet.transfer.completed', [
                'user_id' => $fromUserId,
                'transaction_id' => $fromTransaction->transaction_id,
                'result' => [
                    'to_user_id' => $toUserId,
                    'amount' => $amount,
                    'currency' => $currency,
                ]
            ]);
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $fromTransaction;

        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('wallet.transfer.failed', [
                'from_user_id' => $fromUserId,
                'to_user_id' => $toUserId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function reverseTransaction(string $transactionId, string $reason = '', ?int $adminId = null): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction) {
            $this->logger->warning('wallet.reverse_transaction.not_found', [
                'channel' => 'wallet',
                'transaction_id' => $transactionId,
            ]);
            return false;
        }

        if ($transaction->status !== 'completed') {
            $this->logger->warning('wallet.reverse_transaction.invalid_status', [
                'channel' => 'wallet',
                'transaction_id' => $transactionId,
                'status' => $transaction->status,
            ]);
            return false;
        }

        $userId = (int)$transaction->user_id;

        try {
            return $this->lockService->synchronized("wallet:mut:{$userId}", function() use ($userId, $transaction, $transactionId, $reason) {
                $startedTransaction = !$this->db->inTransaction();

                try {
                    if ($startedTransaction) {
                        $this->db->beginTransaction();
                    }

                    $wallet = $this->walletModel->findByUserId($userId);
                    if (!$wallet) {
                        throw new \RuntimeException('خطا در دریافت wallet');
                    }

                    $amount = (string)$transaction->amount;
                    $currency = (string)$transaction->currency;
                    $scale = $this->getScale($currency);

                    $balanceField = $this->balanceField($currency);
                    $currentBalance = (string)($wallet->$balanceField ?? '0');

                    $reverseAmount = bcmul($amount, '-1', $scale);
                    $balanceAfter = bcadd($currentBalance, $reverseAmount, $scale);

                    if (bccomp($balanceAfter, '0', $scale) < 0) {
                        throw new \RuntimeException("موجودی کافی برای بازگشت تراکنش وجود ندارد (موجودی فعلی: {$currentBalance}، مقدار مورد نیاز برای کسر: {$amount})");
                    }

                    if (!$this->walletModel->updateBalance($userId, $reverseAmount, $currency)) {
                        throw new \RuntimeException('خطا در بروزرسانی موجودی در تراکنش بازگشت');
                    }

                    $this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'reversed');

                    $ipAddress = function_exists('get_client_ip') ? get_client_ip() : '127.0.0.1';
                    $deviceFingerprint = function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : 'system_internal';

                    $revTx = $this->transactionModel->create([
                        'user_id'            => $userId,
                        'type'               => 'reverse',
                        'currency'           => $currency,
                        'amount'             => $reverseAmount,
                        'balance_before'     => $currentBalance,
                        'balance_after'      => $balanceAfter,
                        'status'             => 'completed',
                        'description'        => "برگشت تراکنش {$transactionId} - علت: " . ($reason ?: 'نامشخص'),
                        'ref_id'             => $transactionId,
                        'ref_type'           => 'transaction',
                        'request_id'         => get_request_id(),
                        'ip_address'         => $ipAddress,
                        'device_fingerprint' => $deviceFingerprint,
                        'metadata'           => json_encode(['original_transaction_id' => $transactionId, 'reason' => $reason]),
                    ]);

                    if (!$revTx) {
                        throw new \RuntimeException('خطا در ثبت تراکنش برگشت');
                    }

                    $this->ledger()->recordDoubleEntry(
                        $revTx->transaction_id,
                        "wallet_reversal_escrow",
                        "wallet:{$userId}",
                        abs((float)$reverseAmount),
                        $currency,
                        "برگشت تراکنش {$transactionId}",
                        [
                            'original_transaction_id' => $transactionId,
                            'reason' => $reason
                        ]
                    );

                    $this->dispatchWalletEvent('wallet.transaction.reversed', $userId, $transactionId, [
                        'amount' => $amount,
                        'currency' => $currency,
                        'reverse_transaction_id' => $revTx->transaction_id,
                    ]);

                    if ($startedTransaction) {
                        $this->db->commit();
                    }

                    return true;

                } catch (\Throwable $e) {
                    if ($startedTransaction && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $this->logger->error('wallet.reverse_transaction.failed', [
                        'channel' => 'wallet',
                        'user_id' => $userId,
                        'transaction_id' => $transactionId,
                        'error' => $e->getMessage(),
                    ]);
                    return false;
                }
            }, 15, 10);
        } catch (\RuntimeException $e) {
            $this->logger->warning('wallet.reverse_transaction.lock_timeout', [
                'channel' => 'wallet',
                'user_id' => $userId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getUserTransactions(int $userId, int $limit, int $offset, array $filters = []): array
    {
        $type = $filters['type'] ?? null;
        $currency = $filters['currency'] ?? null;
        return $this->transactionModel->getUserTransactions($userId, $type, $currency, $limit, $offset);
    }

    public function countUserTransactions(int $userId, array $filters = []): int
    {
        $type = $filters['type'] ?? null;
        $currency = $filters['currency'] ?? null;
        return $this->transactionModel->countUserTransactions($userId, $type, $currency);
    }

    public function getAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null, int $limit = 50, int $offset = 0): array
    {
        return $this->transactionModel->getAll($status, $type, $currency, $limit, $offset);
    }

    public function countAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null): int
    {
        return $this->transactionModel->countAll($status, $type, $currency);
    }

    public function findTransactionById(int $id): ?object
    {
        return $this->transactionModel->find($id);
    }

    public function quickSearchTransactions(string $term, ?int $userId = null, int $limit = 5): array
    {
        $filters = [];
        if ($userId !== null) {
            $filters['user_id'] = $userId;
        }

        $res = $this->transactionModel->searchNative($term, $filters, $limit, 0);
        return $res['items'] ?? [];
    }

    public function getBalance(int $userId, string $currency = 'irt'): string
    {
        return $this->walletModel->getBalance($userId, $currency);
    }

    public function getBalanceForUpdate(int $userId, string $currency = 'irt'): string
    {
        return $this->walletModel->getBalanceForUpdate($userId, $currency);
    }

    public function isWalletFrozen(int $userId): bool
    {
        $wallet = $this->walletModel->findByUserId($userId);
        return $wallet ? (bool)($wallet->is_frozen ?? 0) : false;
    }

    private function invalidateWalletCaches(int $userId): void
    {
        $this->cacheInvalidation->invalidateWallet($userId);

        \Core\EventDispatcher::getInstance()->dispatch('wallet.balance_changed', [
            'user_id' => $userId,
            'timestamp' => time()
        ]);
    }

    


    private function dispatchWalletEvent(string $eventName, int $userId, $transaction = null, array $metadata = []): void
    {
        if (isset($this->events)) {
            $this->events->dispatchAsync($eventName, [
                'user_id' => $userId,
                'transaction_id' => $transaction->transaction_id ?? $transaction->id ?? null,
                'metadata' => $metadata
            ]);
        }
    }
}