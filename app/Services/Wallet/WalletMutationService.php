<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Domain\Financial\Services\LedgerService;
use Core\Database;
use App\Services\AntiFraud\FraudGuardService;
use App\Services\Settings\AppSettings;
use Core\EventDispatcher;
use Core\ValueObjects\Money;

class WalletMutationService
{
    private Database $db;
    private Wallet $walletModel;
    private Transaction $transactionModel;
    private LedgerService $ledgerService;
    private FraudGuardService $fraudGuard;
    private AppSettings $appSettings;
    private EventDispatcher $events;
    
    private array $supportedCurrencies;

    public function __construct(
        Database $db,
        Wallet $walletModel,
        Transaction $transactionModel,
        LedgerService $ledgerService,
        FraudGuardService $fraudGuard,
        AppSettings $appSettings,
        EventDispatcher $events
    ) {
        $this->db = $db;
        $this->walletModel = $walletModel;
        $this->transactionModel = $transactionModel;
        $this->ledgerService = $ledgerService;
        $this->fraudGuard = $fraudGuard;
        $this->appSettings = $appSettings;
        $this->events = $events;

        $this->supportedCurrencies = ['irt', 'usdt'];
        $configuredCurrencies = $appSettings->get('wallet_supported_currencies');
        if (is_array($configuredCurrencies) && !empty($configuredCurrencies)) {
            $this->supportedCurrencies = array_map('strtolower', $configuredCurrencies);
        }
    }

    private function getScale(string $currency): int
    {
        return strtolower($currency) === 'usdt' ? 8 : 4;
    }

    private function balanceField(string $currency): string
    {
        return strtolower($currency) === 'usdt' ? 'balance_usdt' : 'balance_irt';
    }

    public function processDeposit(int $userId, string $amount, string $currency, array $metadata, string $requestId, string $ipAddress, string $deviceFingerprint): array
    {
        $currency = strtolower($currency);
        
        // Use non-locking SELECT to check current state
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

        $balanceField = $this->balanceField($currency);
        $balanceBefore = (string)($wallet->$balanceField ?? '0');
        
        $balanceMoney = Money::fromString($balanceBefore, $currency);
        $amountMoney = Money::fromString($amount, $currency);
        $balanceAfter = $balanceMoney->add($amountMoney)->getAmount();

        // Atomic DB Update (removes FOR UPDATE queue wait bottleneck)
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
            'idempotency_key'        => $metadata['idempotency_key'] ?? null,
            'metadata'               => json_encode(array_merge($metadata, [
                'request_id' => $requestId, 'ip' => $ipAddress,
                'device' => $deviceFingerprint,
                'timestamp' => date('Y-m-d H:i:s'),
            ]), JSON_UNESCAPED_UNICODE),
        ]);

        if (!$transaction) {
            throw new \RuntimeException('خطا در ثبت تراکنش');
        }

        $this->ledgerService->recordDoubleEntry(
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

        return [
            'success'        => true,
            'transaction_id' => $transaction->transaction_id,
            'message'        => 'واریز با موفقیت انجام شد',
            'new_balance'    => $balanceAfter,
            'amount'         => $amount,
            'currency'       => $currency,
            'status'         => 'completed',
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
        ];
    }

    public function processWithdraw(int $userId, string $amount, string $currency, array $metadata, string $requestId, string $ipAddress, string $deviceFingerprint): array
    {
        $currency = strtolower($currency);
        
        // Non-locking read
        $wallet = $this->walletModel->findByUserId($userId);
        if (!$wallet) {
            throw new \RuntimeException('خطا در دریافت کیف پول');
        }
        if ((bool)($wallet->is_frozen ?? 0)) {
            throw new \RuntimeException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
        }

        $balanceField = $this->balanceField($currency);
        $currentBalance = (string)($wallet->$balanceField ?? '0');
        
        $currentMoney = Money::fromString($currentBalance, $currency);
        $amountMoney = Money::fromString($amount, $currency);

        if ($amountMoney->isGreaterThan($currentMoney)) {
            throw new \RuntimeException("موجودی کافی نیست (موجودی فعلی: {$currentMoney->format()})");
        }

        $balanceBefore = $currentBalance;
        $balanceAfter = $currentMoney->subtract($amountMoney)->getAmount();

        // Atomic lock balance update
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
            'ref_id'             => $metadata['ref_id'] ?? null,
            'ref_type'           => $metadata['ref_type'] ?? null,
            'request_id'         => $requestId,
            'ip_address'             => $ipAddress,
            'device_fingerprint'     => $deviceFingerprint,
            'idempotency_key'        => $metadata['idempotency_key'] ?? null,
            'metadata'           => json_encode(array_merge($metadata, [
                'request_id' => $requestId, 'ip' => $ipAddress,
                'device' => $deviceFingerprint,
            ]), JSON_UNESCAPED_UNICODE),
        ]);

        if (!$transaction) {
            throw new \RuntimeException('خطا در ثبت تراکنش');
        }

        $result = [
            'success'        => true,
            'transaction_id' => $transaction->transaction_id,
            'message'        => 'درخواست برداشت ثبت شد و منتظر تایید است',
            'new_balance'    => $balanceAfter,
            'amount'         => $amount,
            'currency'       => $currency,
            'status'         => 'pending',
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
        ];
        
        $this->events->dispatchAsync('wallet.withdraw.requested', [
            'user_id' => $userId,
            'transaction_id' => $transaction->transaction_id,
            'metadata' => $result
        ]);
        
        return $result;
    }

    public function processPay(int $userId, string $amount, string $currency, array $metadata, string $requestId, string $ipAddress, string $deviceFingerprint): array
    {
        $currency = strtolower($currency);
        
        // Non-locking read
        $wallet = $this->walletModel->findByUserId($userId);
        if (!$wallet) {
            throw new \RuntimeException('خطا در دریافت کیف پول');
        }
        if ((bool)($wallet->is_frozen ?? 0)) {
            throw new \RuntimeException('کیف پول شما مسدود شده و امکان انجام عملیات وجود ندارد');
        }

        $balanceField = $this->balanceField($currency);
        $currentBalance = (string)($wallet->$balanceField ?? '0');
        
        $currentMoney = Money::fromString($currentBalance, $currency);
        $amountMoney = Money::fromString($amount, $currency);

        if ($amountMoney->isGreaterThan($currentMoney)) {
            throw new \RuntimeException("موجودی کافی نیست (موجودی فعلی: {$currentMoney->format()})");
        }

        $balanceBefore = $currentBalance;
        $balanceAfter = $currentMoney->subtract($amountMoney)->getAmount();

        $negativeAmount = $amountMoney->multiply('-1')->getAmount();
        
        // Atomic balance update
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
            'ref_id'             => $metadata['ref_id'] ?? null,
            'ref_type'           => $metadata['ref_type'] ?? null,
            'request_id'         => $requestId,
            'ip_address'         => $ipAddress,
            'device_fingerprint' => $deviceFingerprint,
            'idempotency_key'    => $metadata['idempotency_key'] ?? null,
            'metadata'           => json_encode(array_merge($metadata, [
                'request_id' => $requestId, 'ip' => $ipAddress,
                'device' => $deviceFingerprint,
            ]), JSON_UNESCAPED_UNICODE),
        ]);

        if (!$transaction) {
            throw new \RuntimeException('خطا در ثبت تراکنش پرداخت');
        }

        $this->ledgerService->recordDoubleEntry(
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

        return [
            'success'        => true,
            'transaction_id' => $transaction->transaction_id,
            'message'        => 'پرداخت با موفقیت انجام شد',
            'new_balance'    => $balanceAfter,
            'amount'         => $amount,
            'currency'       => $currency,
            'status'         => 'completed',
            'balance_before' => $balanceBefore,
            'balance_after'  => $balanceAfter,
        ];
    }
    
    public function processTransfer(int $fromUserId, int $toUserId, string $amount, string $currency, string $description): object
    {
        $currency = strtolower($currency);
        
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
        
        $fromMoney = Money::fromString($fromBalance, $currency);
        $toMoney = Money::fromString($toBalanceBefore, $currency);
        $amountMoney = Money::fromString($amount, $currency);

        if ($amountMoney->isGreaterThan($fromMoney)) {
            throw new \RuntimeException("موجودی کافی نیست (موجودی فعلی: {$fromMoney->format()})");
        }

        $negativeAmount = $amountMoney->multiply('-1')->getAmount();
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
            'balance_after'      => $fromMoney->subtract($amountMoney)->getAmount(),
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
            'balance_after'      => $toMoney->add($amountMoney)->getAmount(),
            'status'             => 'completed',
            'description'        => $description ?: "دریافت از کاربر {$fromUserId}",
            'ip_address'         => $ipAddress,
            'device_fingerprint' => $deviceFingerprint,
            'metadata'           => json_encode(['from_user_id' => $fromUserId]),
        ]);

        if ($fromTransaction && $transaction) {
            $this->ledgerService->recordDoubleEntry(
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
        }

        return $fromTransaction;
    }

    public function completeWithdrawal(string $transactionId, int $userId): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || (int)$transaction->user_id !== $userId) {
            return false;
        }

        if ($transaction->status === 'completed') {
            return true;
        }

        if (!in_array($transaction->status, ['pending', 'processing'], true) || $transaction->type !== 'withdraw') {
            return false;
        }

        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $amount = (string)($transaction->amount ?? '0');

        if (bccomp($amount, '0', 8) <= 0) {
            return false;
        }

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            $this->walletModel->deductLocked($userId, $amount, $currency);

            if (!$this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'completed')) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return false;
            }

            $this->ledgerService->recordDoubleEntry(
                $transactionId,
                'withdrawal_payout',
                "wallet:{$userId}",
                $amount,
                $currency,
                'تسویه برداشت',
                [
                    'withdrawal_transaction' => $transactionId,
                ]
            );

            if ($startedTransaction) {
                $this->db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function cancelWithdrawal(string $transactionId, int $userId): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction || (int)$transaction->user_id !== $userId) {
            return false;
        }

        if (in_array($transaction->status, ['cancelled', 'failed'], true)) {
            return true;
        }

        if (!in_array($transaction->status, ['pending', 'processing'], true) || $transaction->type !== 'withdraw') {
            return false;
        }

        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $amount = (string)($transaction->amount ?? '0');

        if (bccomp($amount, '0', 8) <= 0) {
            return false;
        }

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            $this->walletModel->unlockBalance($userId, $amount, $currency);

            if (!$this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'cancelled')) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return false;
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function reverseTransaction(string $transactionId, ?int $adminId = null, string $reason = ''): bool
    {
        $transaction = $this->transactionModel->findByTransactionId($transactionId);
        if (!$transaction) {
            return false;
        }

        if (in_array($transaction->status, ['cancelled', 'failed', 'reversed'], true)) {
            return true;
        }

        if ($transaction->status !== 'completed') {
            return false;
        }

        $userId = (int)$transaction->user_id;
        $currency = strtolower((string)($transaction->currency ?? 'irt'));
        $amount = (string)($transaction->amount ?? '0');
        if (bccomp($amount, '0', 8) === 0) {
            return false;
        }

        $absoluteAmount = bccomp($amount, '0', 8) < 0 ? bcmul($amount, '-1', 8) : $amount;
        $shouldCreditUser = bccomp($amount, '0', 8) < 0 || $transaction->type === 'withdraw';
        $balanceBefore = $this->walletModel->getBalanceForUpdate($userId, $currency);
        $balanceBeforeMoney = \Core\ValueObjects\Money::fromString($balanceBefore, $currency);
        $amountMoney = \Core\ValueObjects\Money::fromString($absoluteAmount, $currency);

        $startedTransaction = !$this->db->inTransaction();
        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }

            if ($shouldCreditUser) {
                $this->walletModel->updateBalance($userId, $absoluteAmount, $currency);
                $balanceAfter = $balanceBeforeMoney->add($amountMoney)->getAmount();
                $debitAccount = 'reversal_settlement';
                $creditAccount = "wallet:{$userId}";
            } else {
                $negativeAmount = $amountMoney->multiply('-1')->getAmount();
                $this->walletModel->updateBalance($userId, $negativeAmount, $currency);
                $balanceAfter = $balanceBeforeMoney->subtract($amountMoney)->getAmount();
                $debitAccount = "wallet:{$userId}";
                $creditAccount = 'reversal_settlement';
            }

            $reversalTransaction = $this->transactionModel->create([
                'user_id'            => $userId,
                'type'               => 'reversal',
                'currency'           => $currency,
                'amount'             => $shouldCreditUser ? $absoluteAmount : $negativeAmount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'completed',
                'description'        => $reason ?: "بازگشت تراکنش {$transactionId}",
                'ref_id'             => $transactionId,
                'ref_type'           => 'transaction_reversal',
                'metadata'           => json_encode([
                    'original_transaction_id' => $transactionId,
                    'admin_id' => $adminId,
                    'reason' => $reason,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            if (!$reversalTransaction) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return false;
            }

            $this->ledgerService->recordDoubleEntry(
                $reversalTransaction->transaction_id,
                $debitAccount,
                $creditAccount,
                $absoluteAmount,
                $currency,
                $reason ?: "بازگشت تراکنش {$transactionId}",
                [
                    'original_transaction_id' => $transactionId,
                    'admin_id' => $adminId,
                ]
            );

            if (!$this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'reversed')) {
                if ($startedTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return false;
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}

