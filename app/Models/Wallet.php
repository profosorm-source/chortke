<?php

namespace App\Models;

use Core\Model;

class Wallet extends Model
{
    protected static string $table = 'wallets';

    public function __construct(\Core\Database $db)
    {
        parent::__construct($db);
    }

    private function currencyField(string $currency): string
    {
        $currency = \strtolower(\trim($currency));
        return $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
    }

    private function lockedField(string $currency): string
    {
        $currency = \strtolower(\trim($currency));
        return $currency === 'usdt' ? 'locked_usdt' : 'locked_irt';
    }

    /**
     * ایجاد کیف پول برای کاربر جدید
     */
    public function createForUser(int $userId): ?object
    {
        $sql = "
            INSERT INTO " . static::$table . " (user_id, created_at, updated_at)
            VALUES (:user_id, NOW(), NOW())
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        return $this->findByUserId($userId);
    }

    /**
     * دریافت کیف پول بر اساس user_id
     */
    public function findByUserId(int $userId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE user_id = :user_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * دریافت موجودی (بر اساس ارز)
     */
    public function getBalance(int $userId, string $currency = 'irt'): float
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) return 0.0;

        $field = $this->currencyField($currency);
        return (float)($wallet->{$field} ?? 0);
    }

    /**
     * دریافت موجودی قفل‌شده
     */
    public function getLockedBalance(int $userId, string $currency = 'irt'): float
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) return 0.0;

        $field = $this->lockedField($currency);
        return (float)($wallet->{$field} ?? 0);
    }

    /**
     * بررسی وضعیت مسدود بودن کیف پول
     */
    public function isFrozen(int $userId): bool
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) {
            return false;
        }

        return (bool)($wallet->is_frozen ?? 0);
    }

    /**
     * مسدود کردن کیف پول برای کاربر
     */
    public function freezeWallet(int $userId): bool
    {
        $sql = "UPDATE " . static::$table . " SET is_frozen = 1, updated_at = NOW() WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['user_id' => $userId]);
    }

    /**
     * رفع مسدودیت کیف پول برای کاربر
     */
    public function unfreezeWallet(int $userId): bool
    {
        $sql = "UPDATE " . static::$table . " SET is_frozen = 0, updated_at = NOW() WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['user_id' => $userId]);
    }

    /**
     * بروزرسانی موجودی
     */
    public function updateBalance(int $userId, float $amount, string $currency = 'irt'): bool
    {
        $field = $this->currencyField($currency);

        $sql = "
            UPDATE " . static::$table . "
            SET {$field} = {$field} + :amount, updated_at = NOW()
            WHERE user_id = :user_id
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'amount' => $amount,
            'user_id' => $userId,
        ]);
    }

    /**
     * قفل کردن موجودی (برای برداشت)
     */
    public function lockBalance(int $userId, float $amount, string $currency = 'irt'): bool
    {
        $balanceField = $this->currencyField($currency);
        $lockedField  = $this->lockedField($currency);

        $sql = "
            UPDATE " . static::$table . "
            SET
              `{$balanceField}` = `{$balanceField}` - ?,
              `{$lockedField}`  = `{$lockedField}` + ?,
              updated_at = NOW()
            WHERE user_id = ?
              AND `{$balanceField}` >= ?
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $amount,
            $amount,
            $userId,
            $amount,
        ]);
    }

    /**
     * آزاد کردن موجودی قفل‌شده
     */
    public function unlockBalance(int $userId, float $amount, string $currency = 'irt'): bool
    {
        $balanceField = $this->currencyField($currency);
        $lockedField  = $this->lockedField($currency);

        $sql = "
            UPDATE " . static::$table . "
            SET
              `{$balanceField}` = `{$balanceField}` + ?,
              `{$lockedField}`  = `{$lockedField}` - ?,
              updated_at = NOW()
            WHERE user_id = ?
              AND `{$lockedField}` >= ?
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $amount,
            $amount,
            $userId,
            $amount,
        ]);
    }

    /**
     * کسر از موجودی قفل‌شده (برای تکمیل برداشت)
     */
    public function deductLocked(int $userId, float $amount, string $currency = 'irt'): bool
    {
        $lockedField = $this->lockedField($currency);

        $sql = "
            UPDATE " . static::$table . "
            SET `{$lockedField}` = `{$lockedField}` - ?, updated_at = NOW()
            WHERE user_id = ?
              AND `{$lockedField}` >= ?
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $amount,
            $userId,
            $amount,
        ]);
    }

    /**
     * بروزرسانی زمان آخرین برداشت
     */
    public function updateLastWithdrawal(int $userId): bool
    {
        $sql = "
            UPDATE " . static::$table . "
            SET last_withdrawal_at = NOW(), updated_at = NOW()
            WHERE user_id = ?
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }

    /**
     * بررسی امکان برداشت (روزی یکبار)
     */
    public function canWithdrawToday(int $userId): bool
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet || !$wallet->last_withdrawal_at) return true;

        $lastWithdrawal = \strtotime((string)$wallet->last_withdrawal_at);
        $today = \strtotime('today');

        return $lastWithdrawal < $today;
    }

    /**
     * موجودی کل (آزاد + قفل‌شده)
     */
    public function getTotalBalance(int $userId, string $currency = 'irt'): float
    {
        $wallet = $this->findByUserId($userId);
        if (!$wallet) return 0.0;

        if (\strtolower(\trim($currency)) === 'usdt') {
            return (float)$wallet->balance_usdt + (float)$wallet->locked_usdt;
        }

        return (float)$wallet->balance_irt + (float)$wallet->locked_irt;
    }

    /**
     * تنظیم موجودی به مقدار مشخص (نه افزایش/کاهش)
     * برای استفاده داخل تراکنش‌ها با مقدار از پیش محاسبه‌شده
     */
    public function setBalance(int $userId, float $newBalance, string $currency = 'irt'): bool
    {
        $field = $this->currencyField($currency);

        $sql = "UPDATE " . static::$table . "
                SET {$field} = :balance, updated_at = NOW()
                WHERE user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['balance' => $newBalance, 'user_id' => $userId]);
    }

    /**
     * دریافت wallet با قفل (SELECT FOR UPDATE) برای استفاده داخل تراکنش
     * اگر wallet وجود نداشت ایجاد می‌کند (UPSERT) و سپس قفل می‌زند
     */
    public function findByUserIdForUpdate(int $userId): ?object
    {
        // UPSERT - اگر وجود نداشت بساز، اگر داشت همان row رو برگردون
        $upsertSql = "INSERT INTO " . static::$table . " (user_id, balance_irt, balance_usdt, created_at)
                      VALUES (:user_id, 0, 0, NOW())
                      ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)";

        $stmt = $this->db->prepare($upsertSql);
        $stmt->execute(['user_id' => $userId]);

        // حالا با SELECT FOR UPDATE قفل بزن
        $sql = "SELECT * FROM " . static::$table . " WHERE user_id = :user_id FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * بروزرسانی موجودی و زمان آخرین برداشت با هم
     */
    public function setBalanceAndWithdrawalTime(int $userId, float $newBalance, string $currency = 'irt'): bool
    {
        $field = $this->currencyField($currency);

        $sql = "UPDATE " . static::$table . "
                SET {$field} = :balance, last_withdrawal_at = NOW(), updated_at = NOW()
                WHERE user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['balance' => $newBalance, 'user_id' => $userId]);
    }

    /**
     * دریافت wallet برای یک کاربر با قفل‌زدن row کناری (SELECT FOR UPDATE)
     * برای استفاده در transfer بین کاربران
     */
    public function findByUserIdLocked(int $userId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE user_id = :user_id FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }
}