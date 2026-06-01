<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AccountDeletedEvent;
use App\Contracts\WalletServiceInterface;
use App\Contracts\NotificationServiceInterface;
use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * HandleAccountDeleted
 *
 * پس از حذف حساب کاربری:
 * ۱. بررسی و یادداشت موجودی کیف پول باقی‌مانده
 * ۲. بستن escrowهای باز (تبلیغات، تسک‌ها)
 * ۳. اطلاع‌رسانی به ادمین در صورت وجود موجودی معوقه
 * ۴. ثبت آدیت نهایی حذف حساب
 */
class HandleAccountDeleted
{
    // آستانه موجودی‌ای که alert به ادمین ارسال می‌شود (تومان)
    private const BALANCE_ALERT_THRESHOLD = 1000.0;

    private WalletServiceInterface $walletService;
    private NotificationServiceInterface $notificationService;
    private LoggerInterface $logger;
    private Database $db;
    public function __construct(
        WalletServiceInterface $walletService,
        NotificationServiceInterface $notificationService,
        LoggerInterface $logger,
        Database $db
    ) {        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
        $this->db = $db;
}

    public function handle(AccountDeletedEvent $event): void
    {
        $userId = $event->userId;
        $email  = $event->email;
        $reason = $event->reason;

        // ۱. بررسی موجودی باقی‌مانده در کیف پول‌ها
        $this->checkAndAlertRemainingBalance($userId, $email);

        // ۲. بستن escrowهای باز مرتبط با این کاربر
        $this->resolveOpenEscrows($userId);

        // ۳. ثبت آدیت نهایی
        $this->recordFinalAudit($userId, $email, $reason);
    }

    private function checkAndAlertRemainingBalance(int $userId, string $email): void
    {
        try {
            $irtBalance  = (float) $this->walletService->getBalance($userId, 'irt');
            $usdtBalance = (float) $this->walletService->getBalance($userId, 'usdt');

            if ($irtBalance < self::BALANCE_ALERT_THRESHOLD && $usdtBalance < 1.0) {
                return;
            }

            $this->notificationService->sendToAdmins(
                'deleted_account_has_balance',
                '🚨 حساب حذف‌شده با موجودی',
                "کاربر #{$userId} ({$email}) حذف شد اما موجودی دارد.\n"
                . "موجودی IRT: " . number_format($irtBalance) . " تومان\n"
                . "موجودی USDT: {$usdtBalance}",
                [
                    'user_id'      => $userId,
                    'email'        => $email,
                    'irt_balance'  => $irtBalance,
                    'usdt_balance' => $usdtBalance,
                ],
                'high'
            );

            $this->logger->critical('account_deleted.balance_remaining', [
                'user_id'      => $userId,
                'email'        => $email,
                'irt_balance'  => $irtBalance,
                'usdt_balance' => $usdtBalance,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('account_deleted.balance_check_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function resolveOpenEscrows(int $userId): void
    {
        try {
            // Soft-close تبلیغات فعال کاربر (بودجه باقی‌مانده freeze می‌شود)
            $this->db->prepare(
                "UPDATE ads
                 SET status = 'cancelled', updated_at = NOW()
                 WHERE user_id = ? AND status IN ('active', 'pending')"
            )->execute([$userId]);

            // Soft-close سفارش‌های باز سرمایه‌گذاری (escrow)
            $this->db->prepare(
                "UPDATE investments
                 SET status = 'cancelled', updated_at = NOW()
                 WHERE user_id = ? AND status = 'pending'"
            )->execute([$userId]);

            // لغو تسک‌های شروع‌شده اما تکمیل‌نشده
            $this->db->prepare(
                "UPDATE custom_task_submissions
                 SET status = 'cancelled', updated_at = NOW()
                 WHERE worker_id = ? AND status = 'pending'"
            )->execute([$userId]);

            $this->logger->info('account_deleted.escrows_resolved', [
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('account_deleted.escrow_resolution_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function recordFinalAudit(int $userId, string $email, string $reason): void
    {
        try {
            $this->db->prepare(
                "INSERT INTO audit_logs (user_id, event, details, created_at)
                 VALUES (?, 'account.deleted', ?, NOW())"
            )->execute([
                $userId,
                json_encode([
                    'email'  => $email,
                    'reason' => $reason,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('account_deleted.audit_failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
