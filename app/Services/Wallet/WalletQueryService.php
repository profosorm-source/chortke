<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Services\Settings\AppSettings;
use Core\Database;

class WalletQueryService
{
private Wallet $walletModel;
    private Transaction $transactionModel;
    private AppSettings $appSettings;

    public function __construct(
        Database $db,
        Wallet $walletModel,
        Transaction $transactionModel,
        AppSettings $appSettings
    ) {
        $this->db = $db;
        $this->walletModel = $walletModel;
        $this->transactionModel = $transactionModel;
        $this->appSettings = $appSettings;
    }

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
        $irtAvailable = \Core\ValueObjects\Money::fromString((string)($irtBalance))->subtract(\Core\ValueObjects\Money::fromString((string)($irtLocked)))->getAmount();

        $usdtBalance = (string)($wallet->balance_usdt ?? '0');
        $usdtLocked = (string)($wallet->locked_usdt ?? '0');
        $usdtAvailable = \Core\ValueObjects\Money::fromString((string)($usdtBalance))->subtract(\Core\ValueObjects\Money::fromString((string)($usdtLocked)))->getAmount();

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

    public function canWithdraw(int $userId, string $amount, string $currency = 'irt'): array
    {
        $result = ['can_withdraw' => false, 'message' => ''];
        $scale = strtolower($currency) === 'usdt' ? 8 : 4;

        $balance = $this->db->inTransaction()
            ? $this->walletModel->getBalanceForUpdate($userId, $currency)
            : $this->walletModel->getBalance($userId, $currency);
            
        if (\Core\ValueObjects\Money::fromString((string)($amount))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($balance)))) {
            $result['message'] = 'موجودی کافی نیست';
            return $result;
        }

        if (!$this->walletModel->canWithdrawToday($userId)) {
            $result['message'] = 'شما امروز یکبار برداشت کرده‌اید';
            return $result;
        }

        $minWithdrawal = ($currency === 'usdt')
            ? (string)$this->appSettings->get('min_withdraw_usdt', '5.0')
            : (string)$this->appSettings->get('min_withdraw_irt', '10000.0');
            
        if (\Core\ValueObjects\Money::fromString((string)($minWithdrawal))->isGreaterThan(\Core\ValueObjects\Money::fromString((string)($amount)))) {
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

        $totalIrt = \Core\ValueObjects\Money::fromString((string)((string)($wallet->balance_irt ?? '0')))->add(\Core\ValueObjects\Money::fromString((string)((string)($wallet->locked_irt ?? '0'))))->getAmount();
        $totalUsdt = \Core\ValueObjects\Money::fromString((string)((string)($wallet->balance_usdt ?? '0')))->add(\Core\ValueObjects\Money::fromString((string)((string)($wallet->locked_usdt ?? '0'))))->getAmount();

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
}

