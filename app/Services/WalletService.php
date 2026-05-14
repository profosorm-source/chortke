<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Models\Wallet;
use App\Models\Transaction;
use Core\Database;
use App\Services\AuditTrail;
use App\Services\LedgerService;
use App\Services\SettingService;
use App\Contracts\WalletServiceInterface;

class WalletService extends \App\Services\BaseService implements WalletServiceInterface
{
    private \Core\IdempotencyKey $idempotencyKey;
    // 🛡️ H14 Fix: Load supported currencies from config instead of hardcoding
    private array $supportedCurrencies = ['irt', 'usdt'];
    private const SUPPORTED_NETWORKS   = ['TRC20', 'BEP20', 'ERC20', 'TON', 'SOL'];
    private const MIN_AMOUNT           = 0.01;

    private Wallet      $walletModel;
    private Transaction $transactionModel;
    private Database    $db;
    private ?LedgerService $ledgerService = null;
    private AuditTrail $auditTrail;
    private DistributedLockService $lockService;
    private SettingService $settingService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;

    public function __construct(
        Database $db,
        \App\Models\Wallet $walletModel,
        \App\Models\Transaction $transactionModel,
        \Core\IdempotencyKey $idempotencyKey,
        LoggerInterface $logger,
        AuditTrail $auditTrail,
        LedgerService $ledgerService,
        DistributedLockService $lockService,
        SettingService $settingService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->walletModel = $walletModel;
        $this->transactionModel = $transactionModel;
        $this->idempotencyKey = $idempotencyKey;
        $this->auditTrail = $auditTrail;
        $this->ledgerService = $ledgerService;
        $this->lockService = $lockService;
        $this->settingService = $settingService;
        $this->fraudGuard = $fraudGuard;

        // 🛡️ H14 Fix: Load supported currencies from config
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
    // Public API
    // ─────────────────────────────────────────────────────────────

    public function getOrCreateWallet(int $userId): ?object
    {
        $wallet = $this->walletModel->findByUserId($userId);
        if (!$wallet) {
            $wallet = $this->walletModel->createForUser($userId);
        }
        return $wallet;
    }

    private function ledger(): LedgerService
    {
        return $this->ledgerService;
    }

    private function assertWalletActive(int $userId): void
    {
        if ($this->walletModel->isFrozen($userId)) {
            throw new \RuntimeException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
        }
    }

    /**
     * افزایش موجودی (واریز) — با Idempotency Protection
     */
    public function deposit(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateDepositInput($userId, $amount, $currency, $metadata);

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "DEP_{$requestId}";

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', [
            $userId, 'deposit', $amount, $currency,
            $metadata['gateway_transaction_id'] ?? '',
            $metadata['ref_id']                 ?? '',
        ]));

        $idempotencyService = $this->idempotencyKey;
        $check = $idempotencyService->check($idempotencyKey, $userId, 'wallet_deposit', [
            'amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress,
        ]);

        if ($check['is_duplicate']) {
            $this->logger->warning('wallet.deposit.duplicate', [
                'channel' => 'wallet',
                'log_id' => $logId,
                'idempotency_key' => $idempotencyKey,
            ]);
            return $this->standardizeResponse($check['result']);
        }

        // Wraps existing logic inside a Distributed Lock specific to this User's Wallet
        return $this->lockService->synchronized("wallet:mut:{$userId}", function() use ($userId, $amount, $currency, $metadata, $idempotencyKey, $requestId, $ipAddress, $deviceFingerprint, $logId) {
            return $this->processDepositTransaction(
                $userId, $amount, $currency, $metadata, $idempotencyKey,
                $requestId, $ipAddress, $deviceFingerprint, $logId
            );
        }, 15, 10); // TTL 15s, Wait up to 10s
    }

    /**
     * افزایش موجودی درون یک تراکنش فعال — بدون قفل توزیع‌شده
     */
    public function depositInTransaction(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateDepositInput($userId, $amount, $currency, $metadata);

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "DEP_TX_{$requestId}";

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', [
            $userId, 'deposit_tx', $amount, $currency,
            $metadata['gateway_transaction_id'] ?? '',
            $metadata['ref_id']                 ?? '',
        ]));

        $idempotencyService = $this->idempotencyKey;
        $check = $idempotencyService->check($idempotencyKey, $userId, 'wallet_deposit_tx', [
            'amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress,
        ]);

        if ($check['is_duplicate']) {
            $this->logger->warning('wallet.deposit_tx.duplicate', [
                'channel' => 'wallet',
                'log_id' => $logId,
                'idempotency_key' => $idempotencyKey,
            ]);
            return $this->standardizeResponse($check['result']);
        }

        return $this->processDepositTransaction(
            $userId, $amount, $currency, $metadata, $idempotencyKey,
            $requestId, $ipAddress, $deviceFingerprint, $logId
        );
    }

    /**
     * اعتبارسنجی ورودی‌های متد واریز
     */
    private function validateDepositInput(int $userId, float $amount, string $currency, array $metadata): void
    {
        $this->validateCurrency($currency);

        $refundTypes = ['withdrawal_refund', 'refund', 'deposit_refund', 'scheduled_payment_refund'];
        if (!in_array($metadata['type'] ?? 'deposit', $refundTypes, true)) {
            $this->assertWalletActive($userId);
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $minAmount = ($currency === 'usdt') 
            ? (float)$this->settingService->get('min_deposit_usdt', 1.0) 
            : (float)$this->settingService->get('min_deposit_irt', 1000.0);
        if ($amount < $minAmount) {
            throw new \InvalidArgumentException("حداقل مبلغ واریز {$minAmount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " است");
        }
    }

    /**
     * پردازش تراکنش واریز در پایگاه داده
     */
    private function processDepositTransaction(
        int $userId, float $amount, string $currency, array $metadata, string $idempotencyKey,
        string $requestId, string $ipAddress, string $deviceFingerprint, string $logId
    ): array {
        $idempotencyService = $this->idempotencyKey;
        $startedTransaction = !$this->db->inTransaction();

        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \RuntimeException('خطا در دریافت wallet');
            }

            $balanceField  = $this->balanceField($currency);
            $balanceBefore = (float)$wallet->$balanceField;
            $balanceAfter  = (float)bcadd((string)$balanceBefore, (string)$amount, 2);

            if (!$this->walletModel->setBalance($userId, $balanceAfter, $currency)) {
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
                $metadata['description'] ?? 'واریز وجه',
                [
                    'gateway' => $metadata['gateway'] ?? null,
                    'ref_id' => $metadata['ref_id'] ?? null,
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
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }
            $idempotencyService->complete($idempotencyKey, $result, $userId);

            $this->auditTrail->record('wallet.credited', $userId, [
                'amount'         => $amount,
                'currency'       => $currency,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'type'           => $metadata['type'] ?? 'deposit',
                'transaction_id' => $transaction->transaction_id,
            ]);

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
            
            // Auto-tagging removed, system natively relies on metadata->type and transaction.type

            return $result;

        } catch (\InvalidArgumentException $e) {
            $failResult = $this->standardizeResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'validation_error',
                'message' => $e->getMessage(),
            ]);

            $idempotencyService->fail($idempotencyKey, $failResult, $userId);

            $this->logger->warning('wallet.credit.validation_failed', [
                'channel' => 'wallet',
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } catch (\Exception $e) {
            if ($startedTransaction) {
                $this->db->rollBack();
            }

            $failResult = $this->standardizeResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'runtime_error',
                'message' => 'خطای سیستمی در فرآیند واریز',
            ]);

            $idempotencyService->fail($idempotencyKey, $failResult, $userId);

            $this->logger->error('wallet.credit.failed', [
                'channel' => 'wallet',
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw $e;
        }
    }

    /**
     * برداشت وجه — با قفل موجودی و Idempotency
     */
    public function withdraw(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array
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
            $this->logger->warning('wallet.withdraw.duplicate', [
                'channel' => 'wallet',
                'log_id' => $logId,
                'idempotency_key' => $idempotencyKey,
            ]);
            return $this->standardizeResponse($check['result']);
        }

        // Wraps existing logic inside a Distributed Lock specific to this User's Wallet
        return $this->lockService->synchronized("wallet:mut:{$userId}", function() use ($userId, $amount, $currency, $metadata, $idempotencyKey, $requestId, $ipAddress, $deviceFingerprint, $logId) {
            return $this->processWithdrawTransaction(
                $userId, $amount, $currency, $metadata, $idempotencyKey,
                $requestId, $ipAddress, $deviceFingerprint, $logId
            );
        }, 15, 10); // TTL 15s, Wait up to 10s
    }

    /**
     * 🛡️ برداشت درون یک تراکنش فعال — بدون قفل توزیع‌شده
     * برای استفاده داخلی توسط سرویس‌های دیگر که قبلاً transaction شروع کرده‌اند
     * 
     * CRITICAL: این متد صرفاً برای internal use است. مطمئن شوید که:
     * - Transaction قبلاً شروع شده است
     * - Wallet با FOR UPDATE قفل شده است
     * - تمام عملیات در یک transaction اتمیک انجام می‌شود
     */
    public function withdrawInTransaction(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateCurrency($currency);
        $this->assertWalletActive($userId);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        // ⚠️ PRECONDITION CHECK: Transaction must be active (caller's responsibility)
        if (!$this->db->inTransaction()) {
            throw new \RuntimeException('withdrawInTransaction() requires an active database transaction. Use withdraw() instead.');
        }

        try {
            // 🔒 MUST be locked: Retrieve wallet with pessimistic lock (should already be locked by caller)
            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \RuntimeException('خطا در دریافت wallet');
            }

            $balanceField = $this->balanceField($currency);
            $currentBalance = (float)$wallet->$balanceField;

            // ✅ Balance check with BCMath for precision
            if (bccomp((string)$currentBalance, (string)$amount, 2) < 0) {
                throw new \RuntimeException("موجودی کافی نیست (موجودی فعلی: {$currentBalance})");
            }

            $balanceBefore = $currentBalance;
            $balanceAfter = (float)bcsub((string)$balanceBefore, (string)$amount, 2);

            // Update balance directly (no separate lock needed due to FOR UPDATE)
            if (!$this->walletModel->setBalance($userId, $balanceAfter, $currency)) {
                throw new \RuntimeException('خطا در بروزرسانی موجودی');
            }

            // Update last withdrawal timestamp
            if (!$this->walletModel->updateLastWithdrawal($userId)) {
                throw new \RuntimeException('خطا در بروزرسانی زمان آخرین برداشت');
            }

            // Create transaction record
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
                    'context'    => 'internal_transaction',
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \RuntimeException('خطا در ثبت تراکنش');
            }

            // Record audit trail
            $this->auditTrail->record('wallet.internal_debited', $userId, [
                'amount'         => $amount,
                'currency'       => $currency,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'type'           => $metadata['type'] ?? 'internal_withdraw',
                'transaction_id' => $transaction->transaction_id,
                'context'        => $metadata['ref_type'] ?? 'unknown',
            ]);

            return [
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'completed',
            ];

        } catch (\Exception $e) {
            $this->logger->error('wallet.withdraw_in_transaction.failed', [
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * اعتبارسنجی ورودی‌های متد برداشت
     */
    private function validateWithdrawInput(int $userId, float $amount, string $currency, array $metadata): void
    {
        $this->validateCurrency($currency);
        $this->assertWalletActive($userId);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $minAmount = ($currency === 'usdt') 
            ? (float)$this->settingService->get('min_withdraw_usdt', 5.0) 
            : (float)$this->settingService->get('min_withdraw_irt', 10000.0);
        if ($amount < $minAmount) {
            throw new \InvalidArgumentException("حداقل مبلغ برداشت {$minAmount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " است");
        }
    }

    /**
     * پردازش تراکنش برداشت در پایگاه داده
     */
    private function processWithdrawTransaction(
        int $userId, float $amount, string $currency, array $metadata, string $idempotencyKey,
        string $requestId, string $ipAddress, string $deviceFingerprint, string $logId
    ): array {
        $idempotencyService = $this->idempotencyKey;
        $startedTransaction = !$this->db->inTransaction();

        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \RuntimeException('خطا در دریافت wallet');
            }

            $balanceField   = $this->balanceField($currency);
            $currentBalance = (float)$wallet->$balanceField;

            if (bccomp((string)$currentBalance, (string)$amount, 2) < 0) {
                throw new \RuntimeException("موجوزی کافی نیست (موجودی فعلی: {$currentBalance})");
            }

            $balanceBefore = $currentBalance;
            $balanceAfter  = (float)bcsub((string)$balanceBefore, (string)$amount, 2);

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
                    'timestamp'  => date('Y-m-d H:i:s'),
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
            ]);

            if ($startedTransaction) {
                $this->db->commit();
            }
            $idempotencyService->complete($idempotencyKey, $result, $userId);

            $this->auditTrail->record('wallet.debited', $userId, [
                'amount'         => $amount,
                'currency'       => $currency,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'type'           => $metadata['type'] ?? 'withdraw',
                'transaction_id' => $transaction->transaction_id,
            ]);

            $this->logger->warning('wallet.withdraw.success', [
                'amount' => $amount,
                'currency' => $currency,
                'user_id' => $userId,
                'transaction_id' => $transaction->transaction_id,
            ]);

            $this->logger->info('wallet.withdraw.success', [
                'channel' => 'wallet',
                'log_id' => $logId,
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => $currency,
            ]);
            
            // Auto-tagging removed, system natively relies on transaction.type

            return $result;

        } catch (\InvalidArgumentException $e) {
            $failResult = $this->standardizeResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'type' => 'validation_error',
                'message' => $e->getMessage(),
            ]);

            $idempotencyService->fail($idempotencyKey, $failResult, $userId);

            $this->logger->warning('wallet.debit.validation_failed', [
                'channel' => 'wallet',
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw $e;
        } catch (\Exception $e) {
            if ($startedTransaction) {
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

    /**
     * پرداخت داخلی فوری — کسر از موجودی بدون قفل کردن (خرید سرویس، سرمایه‌گذاری و غیره)
     */
    public function pay(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateCurrency($currency);
        
        $this->assertWalletActive($userId);

        if ($amount <= 0) {
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

        return $this->lockService->synchronized("wallet:mut:{$userId}", function() use (
            $userId, $amount, $currency, $metadata, $idempotencyKey, 
            $requestId, $ipAddress, $deviceFingerprint, $logId, $idempotencyService
        ) {
            $startedTransaction = !$this->db->inTransaction();

            try {
                if ($startedTransaction) {
                    $this->db->beginTransaction();
                }

                $wallet = $this->walletModel->findByUserIdForUpdate($userId);
                if (!$wallet) {
                    throw new \RuntimeException('خطا در دریافت wallet');
                }

                $balanceField   = $this->balanceField($currency);
                $currentBalance = (float)$wallet->$balanceField;

                if (bccomp((string)$currentBalance, (string)$amount, 2) < 0) {
                    throw new \RuntimeException("موجودی کافی نیست (موجودی فعلی: {$currentBalance})");
                }

                $balanceBefore = $currentBalance;
                $balanceAfter  = (float)bcsub((string)$balanceBefore, (string)$amount, 2);

                if (!$this->walletModel->setBalance($userId, $balanceAfter, $currency)) {
                    throw new \RuntimeException('خطا در کسر موجودی');
                }

                $transaction = $this->transactionModel->create([
                    'user_id'            => $userId,
                    'type'               => $metadata['type'] ?? 'payment',
                    'currency'           => $currency,
                    'amount'             => -$amount, // Negative for payment
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
                        'timestamp'  => date('Y-m-d H:i:s'),
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
                    $metadata['description'] ?? 'پرداخت هزینه',
                    ['type' => $metadata['type'] ?? 'payment']
                );

                $result = $this->standardizeResponse([
                    'success'        => true,
                    'transaction_id' => $transaction->transaction_id,
                    'message'        => 'پرداخت با موفقیت انجام شد',
                    'new_balance'    => $balanceAfter,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'status'         => 'completed',
                ]);

                if ($startedTransaction) {
                    $this->db->commit();
                }
                $idempotencyService->complete($idempotencyKey, $result, $userId);

                $this->auditTrail->record('wallet.paid', $userId, [
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'type'           => $metadata['type'] ?? 'payment',
                    'transaction_id' => $transaction->transaction_id,
                ]);

                return $result;

            } catch (\Exception $e) {
                if ($startedTransaction) {
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
        }, 15, 10); // TTL 15s, Wait up to 10s
    }

    public function hasBalance(int $userId, float $amount, string $currency = 'irt'): bool
    {
        $currency = strtolower($currency);
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return false;
        }
        $balance = $this->walletModel->getBalance($userId, $currency);
        return bccomp((string)$balance, (string)$amount, 2) >= 0;
    }

    public function completeWithdrawal(int $userId, float $amount, string $currency, ?string $transactionId): bool
    {
        $this->assertWalletActive($userId);
        
        try {
            if ($transactionId) {
                $transaction = $this->transactionModel->findByTransactionId($transactionId);
                if ($transaction && $transaction->type === 'withdraw') {
                    if (!$this->walletModel->deductLocked($userId, $amount, $currency)) {
                        $this->logger->warning('wallet.complete_withdrawal.locked_deduction_failed', [
                            'transaction_id' => $transactionId,
                            'user_id' => $userId,
                            'amount' => $amount,
                            'currency' => $currency,
                        ]);
                    } else {
                        $this->ledger()->recordDoubleEntry(
                            $transactionId,
                            'platform_cash',
                            'withdrawal_pending',
                            $amount,
                            'Withdrawal completed',
                            ['user_id' => $userId]
                        );
                    }
                }

                $this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'completed');
            }
            return true;
        } catch (\Exception $e) {
            $this->logger->error('wallet.complete_withdrawal.failed', [
    'channel' => 'wallet',
    'error' => $e->getMessage(),
]);
            return false;
        }
    }

    public function cancelWithdrawal(int $userId, float $amount, string $currency, ?string $transactionId): bool
    {
        try {
            $transaction = null;
            if ($transactionId) {
                $transaction = $this->transactionModel->findByTransactionId($transactionId);
            }

            if ($transaction && $transaction->type === 'withdraw') {
                $balanceBefore = $this->walletModel->getBalance($userId, $currency);
                $lockedBefore  = $this->walletModel->getLockedBalance($userId, $currency);
                $unlockResult  = $this->walletModel->unlockBalance($userId, $amount, $currency);
                if ($unlockResult) {
                    $balanceAfter = (float)bcadd((string)$balanceBefore, (string)$amount, 2);

                    $refundTx = $this->transactionModel->create([
                        'user_id' => $userId,
                        'type' => 'withdrawal_refund',
                        'currency' => $currency,
                        'amount' => $amount,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $balanceAfter,
                        'status' => 'completed',
                        'description' => 'بازگشت وجه برداشت لغو شده',
                        'metadata' => json_encode(['ref_transaction_id' => $transactionId], JSON_UNESCAPED_UNICODE),
                    ]);

                    if ($refundTx) {
                        $this->ledger()->recordDoubleEntry(
                            $refundTx->transaction_id,
                            "wallet:{$userId}",
                            'withdrawal_pending',
                            $amount,
                            'Withdrawal refund',
                            ['original_transaction' => $transactionId]
                        );
                    }
                } else {
                    $transaction = null;
                }
            }

            if (!$transaction) {
                $result = $this->deposit($userId, $amount, $currency, [
                    'type'               => 'withdrawal_refund',
                    'description'        => 'بازگشت وجه برداشت لغو شده',
                    'ref_transaction_id' => $transactionId,
                ]);

                if (!$result['success']) {
                    $this->logger->error('wallet.cancel_withdrawal.deposit_failed', [
                        'channel' => 'wallet',
                        'message' => $result['message'] ?? null,
                    ]);
                    return false;
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

            return true;
        } catch (\Exception $e) {
            $this->logger->error('wallet.cancel_withdrawal.failed', [
                'channel' => 'wallet',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function canWithdraw(int $userId, float $amount, string $currency = 'irt'): array
    {
        $result = ['can_withdraw' => false, 'message' => ''];

        $balance = $this->walletModel->getBalance($userId, $currency);
        if (bccomp((string)$balance, (string)$amount, 2) < 0) {
            $result['message'] = 'موجودی کافی نیست';
            return $result;
        }

        if (!$this->walletModel->canWithdrawToday($userId)) {
            $result['message'] = 'شما امروز یکبار برداشت کرده‌اید';
            return $result;
        }

        $minWithdrawal = ($currency === 'usdt') 
            ? (float)$this->settingService->get('min_withdraw_usdt', 5.0) 
            : (float)$this->settingService->get('min_withdraw_irt', 10000.0);
        if (bccomp((string)$amount, (string)$minWithdrawal, 2) < 0) {
            $result['message'] = 'حداقل مبلغ برداشت ' . number_format($minWithdrawal) . ' ' . ($currency === 'usdt' ? 'USDT' : 'تومان') . ' است';
            return $result;
        }

        $result['can_withdraw'] = true;
        return $result;
    }

    public function getWalletSummary(int $userId): object
    {
        $wallet = $this->getOrCreateWallet($userId);
        $stats  = $this->transactionModel->getUserStats($userId);

        return (object)[
            'balance_irt'        => (float)$wallet->balance_irt,
            'balance_usdt'       => (float)$wallet->balance_usdt,
            'locked_irt'         => (float)$wallet->locked_irt,
            'locked_usdt'        => (float)$wallet->locked_usdt,
            'total_irt'          => (float)$wallet->balance_irt  + (float)$wallet->locked_irt,
            'total_usdt'         => (float)$wallet->balance_usdt + (float)$wallet->locked_usdt,
            'is_frozen'          => (bool)($wallet->is_frozen ?? 0),
            'last_withdrawal_at' => $wallet->last_withdrawal_at,
            'can_withdraw_today' => $this->walletModel->canWithdrawToday($userId),
            'stats'              => $stats,
        ];
    }

    public function transfer(int $fromUserId, int $toUserId, float $amount, string $currency = 'irt', string $description = ''): ?object
    {
        // 🛡️ گیت ضدتقلب انتقال وجه (Velocity check & Global limits)
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

        if ($amount <= 0) {
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

            $firstWallet  = $this->walletModel->findByUserIdForUpdate($firstId);
            $secondWallet = $this->walletModel->findByUserIdForUpdate($secondId);

            if (!$firstWallet || !$secondWallet) {
                throw new \RuntimeException('کیف پول یافت نشد');
            }

            $fromWallet = ($firstId === $fromUserId) ? $firstWallet : $secondWallet;
            $toWallet   = ($firstId === $toUserId)   ? $firstWallet : $secondWallet;

            $balanceField    = $this->balanceField($currency);
            $fromBalance     = (float)$fromWallet->$balanceField;
            $toBalanceBefore = (float)$toWallet->$balanceField;

            if (bccomp((string)$fromBalance, (string)$amount, 2) < 0) {
                throw new \RuntimeException('موجودی کافی نیست');
            }

            $this->walletModel->updateBalance($fromUserId, -$amount, $currency);
            $this->walletModel->updateBalance($toUserId, $amount, $currency);

            $fromTransaction = $this->transactionModel->create([
                'user_id'        => $fromUserId,
                'type'           => 'transfer',
                'currency'       => $currency,
                'amount'         => -$amount,
                'balance_before' => $fromBalance,
                'balance_after'  => (float)bcsub((string)$fromBalance, (string)$amount, 2),
                'status'         => 'completed',
                'description'    => $description ?: "انتقال به کاربر {$toUserId}",
                'metadata'       => json_encode(['to_user_id' => $toUserId]),
            ]);

            $transaction = $this->transactionModel->create([
                'user_id'        => $toUserId,
                'type'           => 'transfer',
                'currency'       => $currency,
                'amount'         => $amount,
                'balance_before' => $toBalanceBefore,
                'balance_after'  => (float)bcadd((string)$toBalanceBefore, (string)$amount, 2),
                'status'         => 'completed',
                'description'    => $description ?: "دریافت از کاربر {$fromUserId}",
                'metadata'       => json_encode(['from_user_id' => $fromUserId]),
            ]);

            if ($fromTransaction) {
                $this->ledger()->recordDoubleEntry(
                    $fromTransaction->transaction_id,
                    "wallet:{$fromUserId}",
                    "wallet:{$toUserId}",
                    $amount,
                    $description ?: "انتقال به کاربر {$toUserId}",
                    ['counterparty' => $toUserId]
                );
            }

            if ($transaction) {
                $this->ledger()->recordDoubleEntry(
                    $transaction->transaction_id,
                    "wallet:{$fromUserId}",
                    "wallet:{$toUserId}",
                    $amount,
                    $description ?: "دریافت از کاربر {$fromUserId}",
                    ['counterparty' => $fromUserId]
                );
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            $this->logger->warning(
                'wallet_transfer', 
                "انتقال {$amount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " به کاربر {$toUserId}", 
                $fromUserId, 
                ['to_user_id' => $toUserId]
            );
            
            $this->auditTrail->record('wallet.transfer', $fromUserId, [
                'to_user_id' => $toUserId,
                'amount' => $amount,
                'currency' => $currency
            ]);

            return $transaction;

        } catch (\Exception $e) {
            if ($startedTransaction) {
                $this->db->rollBack();
            }
            $this->logger->error('wallet.transfer.failed', [
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
                'amount'       => $amount,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getBalance(int $userId, string $currency = 'irt'): float
    {
        $currency = strtolower($currency);
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return 0.0;
        }
        return $this->walletModel->getBalance($userId, $currency);
    }

    public function isWalletFrozen(int $userId): bool
    {
        return $this->walletModel->isFrozen($userId);
    }

    public function freezeWallet(int $userId): bool
    {
        return $this->walletModel->freezeWallet($userId);
    }

    public function unfreezeWallet(int $userId): bool
    {
        return $this->walletModel->unfreezeWallet($userId);
    }

    public function reverseTransaction(string $transactionId, ?int $performedBy = null, ?string $reason = null): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || $transaction->status !== 'completed') {
            return false;
        }

        $amount = (float)$transaction->amount;
        $currency = strtolower($transaction->currency ?? 'irt');
        $userId = (int)$transaction->user_id;
        $balanceBefore = $this->walletModel->getBalance($userId, $currency);
        $balanceAfter = $balanceBefore;
        $reversalType = 'transaction_reversal';
        $description = $reason ?? 'Reversal of transaction ' . $transactionId;

        $transactionDelta = bcsub((string)$transaction->balance_after, (string)$transaction->balance_before, 4);
        $reversalAmount = abs($amount);

        if (bccomp($transactionDelta, '0', 4) < 0) {
            // Original transaction reduced wallet balance, reversal should credit user wallet.
            $balanceAfter = (float)bcadd((string)$balanceBefore, (string)$reversalAmount, 2);
            $this->walletModel->updateBalance($userId, $reversalAmount, $currency);
            $debitAccount = 'transaction_reversal';
            $creditAccount = "wallet:{$userId}";
        } else {
            // Original transaction increased wallet balance, reversal should debit user wallet.
            if (bccomp((string)$balanceBefore, (string)$reversalAmount, 2) < 0) {
                return false;
            }
            $balanceAfter = (float)bcsub((string)$balanceBefore, (string)$reversalAmount, 2);
            $this->walletModel->updateBalance($userId, -$reversalAmount, $currency);
            $debitAccount = "wallet:{$userId}";
            $creditAccount = 'transaction_reversal';
        }

        $reversal = $this->transactionModel->create([
            'user_id' => $userId,
            'type' => $reversalType,
            'currency' => $currency,
            'amount' => ($transactionDelta < 0 ? $reversalAmount : -$reversalAmount),
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'status' => 'completed',
            'description' => $description,
            'metadata' => json_encode(['original_transaction' => $transactionId], JSON_UNESCAPED_UNICODE),
        ]);

        if (!$reversal) {
            return false;
        }

        $this->ledger()->recordDoubleEntry(
            $reversal->transaction_id,
            $debitAccount,
            $creditAccount,
            $reversalAmount,
            $description,
            ['original_transaction' => $transactionId]
        );

        $this->transactionModel->recordStatusChange(
            $transactionId,
            'reversed',
            $reason,
            $performedBy,
            [
                'reversal_transaction_id' => $reversal->transaction_id,
                'ip_address' => $this->clientIp()
            ]
        );

        return true;
    }

    public function updateLedgerStatusByIdempotency(string $idempotencyKey, string $newStatus): bool
    {
        try {
            $affected = $this->transactionModel->updateStatusByIdempotencyKey($idempotencyKey, $newStatus);
            return $affected > 0;
        } catch (\Exception $e) {
            $this->logger->error('wallet.ledger.update_failed', [
                'idempotency_key' => $idempotencyKey,
                'new_status'      => $newStatus,
                'error'           => $e->getMessage(),
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

    /**
     * دریافت لیست کلی تراکنش‌ها (مدیریت)
     */
    public function getAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null, int $limit = 50, int $offset = 0): array
    {
        return $this->transactionModel->getAll($status, $type, $currency, $limit, $offset);
    }

    /**
     * شمارش تعداد کل تراکنش‌ها (مدیریت)
     */
    public function countAllTransactions(?string $status = null, ?string $type = null, ?string $currency = null): int
    {
        return $this->transactionModel->countAll($status, $type, $currency);
    }

    /**
     * یافتن جزئیات یک تراکنش با آی‌دی
     */
    public function findTransactionById(int $id): ?object
    {
        return $this->transactionModel->find($id);
    }

    /**
     * جستجوی هوشمند و سریع تراکنش‌ها (پشتیبانی از سرچ گلوبال و اختصاصی کاربر)
     */
    public function quickSearchTransactions(string $term, ?int $userId = null, int $limit = 5): array
    {
        $filters = [];
        if ($userId !== null) {
            $filters['user_id'] = $userId;
        }

        $res = $this->transactionModel->searchNative($term, $filters, $limit, 0);
        
        return $res['items'] ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────

    private function validateCurrency(string $currency): void
    {
        // 🛡️ H14 Fix: Use configured currencies instead of hardcoded constant
        if (!in_array($currency, $this->supportedCurrencies, true)) {
            $supportedList = implode("'، '", $this->supportedCurrencies);
            throw new \InvalidArgumentException("ارز '{$currency}' پشتیبانی نمی‌شود. فقط '{$supportedList}' معتبر است.");
        }
    }

    private function balanceField(string $currency): string
    {
        return $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
    }

    /**
     * استانداردسازی آرایه پاسخ تراکنش‌ها
     */
    private function standardizeResponse(array $response): array
    {
        return [
            'success'        => (bool)($response['success'] ?? false),
            'transaction_id' => $response['transaction_id'] ?? null,
            'message'        => $response['message'] ?? '',
            'new_balance'    => isset($response['new_balance']) ? (float)$response['new_balance'] : null,
            'amount'         => isset($response['amount']) ? (float)$response['amount'] : null,
            'currency'       => $response['currency'] ?? null,
            'status'         => $response['status'] ?? null,
            'error'          => $response['error'] ?? null,
        ];
    }

}

