<?php
declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;
use App\Models\AccountDeletionLog;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\CustomTask\AdminCustomTaskService;
use Core\EventDispatcher;
use App\Services\DistributedLockService;

use App\Models\Wallet;

/**
 * AccountDeletionService — حذف حساب کاربران
 */
class AccountDeletionService
{
    private User $userModel;
    private AccountDeletionLog $deletionLogModel;

    private AdminCustomTaskService $customTaskService;

    private Wallet $walletModel;
    private DistributedLockService $lockService;
    private \App\Services\EmailService $emailService;

    private \Core\EventDispatcher $eventDispatcher;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\EventDispatcher $eventDispatcher,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        User $userModel,
        AccountDeletionLog $deletionLogModel,
        AdminCustomTaskService $customTaskService,
        Wallet $walletModel,
        DistributedLockService $lockService,
        \App\Services\EmailService $emailService
    ) {        $this->eventDispatcher = $eventDispatcher;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->userModel = $userModel;
        $this->deletionLogModel = $deletionLogModel;
        $this->customTaskService = $customTaskService;
        $this->walletModel = $walletModel;
        $this->lockService = $lockService;
        $this->emailService = $emailService;
    }

    /**
     * حذف خودکار درخواست‌های منقضی
     * این متد باید توسط Cron Job هر روز اجرا شود
     */
    public function processExpiredDeletionRequests(): int
    {
        try {
            // H23 Fix: ارتقای سیستم قفل به سرویس توزیع‌شده جهت تضمین ایمنی کلاستر و جلوگیری از Deadlock
            return $this->lockService->synchronized('cron_account_deletion_lock', function() {
                $expiredRequests = $this->deletionLogModel->getExpiredDeletionRequests();
                $deletedCount = 0;

                foreach ($expiredRequests as $request) {
                    $userId = (int)$request['user_id'];
                    
                    // Pre-check wallet balance (CRIT-02)
                    $wallet = $this->db->fetch(
                        "SELECT balance_irt, balance_usdt FROM wallets WHERE user_id = ?",
                        [$userId]
                    );
                    if ($wallet && ((float)$wallet['balance_irt'] > 0 || (float)$wallet['balance_usdt'] > 0)) {
                        // Cancel the deletion request to preserve customer funds
                        $this->deletionLogModel->cancelDeletionRequest($userId);
                        $this->logger->warning('account_deletion.cancelled_due_to_positive_balance', [
                            'user_id' => $userId,
                            'balance_irt' => $wallet['balance_irt'],
                            'balance_usdt' => $wallet['balance_usdt']
                        ]);
                        continue;
                    }

                    if ($this->deleteUserAccount($userId, 'Automated deletion after 7-day period')) {
                        $deletedCount++;
                    }
                }

                $this->logger->info('account_deletion.automated_completed', ['count' => $deletedCount]);
                return $deletedCount;
            }, ttl: 60, waitTimeout: 0);
        } catch (\RuntimeException $e) {
            $this->logger->warning('account_deletion.cron_skipped_mutex_busy', ['reason' => $e->getMessage()]);
            return 0;
        } catch (\Exception $e) {
            $this->logger->error('account_deletion.automated_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * حذف کامل حساب کاربر
     * 🛡️ MED-13 Fix (CRITICAL): TOCTOU Vulnerability — Move balance check inside transaction
     */
    public function deleteUserAccount(int $userId, ?string $reason = null, ?int $deletedBy = null): bool
    {
        $this->db->beginTransaction();

        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                $this->db->rollback();
                $this->logger->warning('account_deletion.user_not_found', ['user_id' => $userId]);
                return false;
            }

            // Acquire pessimistic lock on wallet to prevent TOCTOU during escrow cancellation
            $stmt = $this->db->prepare("
                SELECT balance_irt, balance_usdt FROM wallets 
                WHERE user_id = ? 
                FOR UPDATE
            ");
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch(\PDO::FETCH_OBJ);

            // ۰. لغو تسک‌های فعال و برگشت اسکروها به کیف پول قبل از حذف مستقیم (Issue #23)
            $this->customTaskService->cancelActiveTasksForUser($userId);

            // ۱. مجدداً کیف پول قفل شده را برای بررسی بالانس نهایی واکشی می‌کنیم تا مطمئن شویم هیچگونه پولی در حساب مسدود نیست
            $stmtCheck = $this->db->prepare("
                SELECT balance_irt, balance_usdt FROM wallets 
                WHERE user_id = ? 
                FOR UPDATE
            ");
            $stmtCheck->execute([$userId]);
            $walletCheck = $stmtCheck->fetch(\PDO::FETCH_OBJ);

            if (!$walletCheck) {
                $balanceIrt = 0;
                $balanceUsdt = 0;
            } else {
                $balanceIrt = (float)$walletCheck->balance_irt;
                $balanceUsdt = (float)$walletCheck->balance_usdt;
            }

            if ($balanceIrt > 0 || $balanceUsdt > 0) {
                $this->db->rollback();
                $this->logger->critical('account_deletion.blocked_positive_balance', [
                    'user_id' => $userId,
                    'balance_irt' => $balanceIrt,
                    'balance_usdt' => $balanceUsdt
                ]);
                return false; // Cannot delete accounts that still hold customer funds (including refunded escrows)
            }

            // ۲. حذف تراکنش‌های کاربر (ثبت شامل)
            $this->db->query("UPDATE transactions SET user_id = NULL, deleted_user_id = ? WHERE user_id = ?", [$userId, $userId]);

            // ۳. حذف وظایف کاربر
            $this->db->query("DELETE FROM custom_task_submissions WHERE user_id = ?", [$userId]);
            $this->db->query("DELETE FROM custom_tasks WHERE user_id = ?", [$userId]);

            // ۴. حذف اعلان‌ها
            $this->db->query("DELETE FROM notifications WHERE user_id = ?", [$userId]);

            // ۵. حذف تنظیمات
            $this->db->query("DELETE FROM user_settings WHERE user_id = ?", [$userId]);

            // ۶. حذف KYC
            $this->db->query("DELETE FROM kyc_verifications WHERE user_id = ?", [$userId]);

            // ۷. حذف کارت‌های بانکی
            $this->db->query("DELETE FROM bank_cards WHERE user_id = ?", [$userId]);

            // ۸. حذف سشن‌ها
            $this->db->query("DELETE FROM user_sessions WHERE user_id = ?", [$userId]);

            // ۹. حذف تنظیمات دو فاکتور
            $this->db->query("DELETE FROM two_factor_codes WHERE user_id = ?", [$userId]);

            // ۱۰. ثبت در account_deletion_logs
            $this->deletionLogModel->recordDeletion($userId, $deletedBy, $reason);

            // ۱۱. حذف کاربر (soft delete یا hard delete)
            // استفاده از UNIX_TIMESTAMP و LEFT برای جلوگیری از collision در ستون‌های Unique (Issue #22)
            // به همراه پاکسازی کد ملی و تغییر شماره موبایل جهت جلوگیری از برخورد با مقادیر Unique
            $uniqueSuffix = \bin2hex(\random_bytes(6));
            $this->db->query(
                "UPDATE users SET 
                    deleted_at = NOW(),
                    email = CONCAT(SUBSTRING(email, 1, 80), '_del_', ?),
                    username = CONCAT(SUBSTRING(username, 1, 30), '_del_', ?),
                    mobile = CONCAT(SUBSTRING(mobile, 1, 3), '_del_', ?),
                    national_id = NULL,
                    status = 'deleted'
                WHERE id = ?",
                [$uniqueSuffix, $uniqueSuffix, $uniqueSuffix, $userId]
            );

            $this->db->commit();

            // ✅ Send confirmation email BEFORE event dispatch
            $toEmail = is_array($user) ? ($user['email'] ?? '') : ($user->email ?? '');
            $toName = is_array($user) ? ($user['full_name'] ?? '') : ($user->full_name ?? '');
            if (empty($toName)) {
                $toName = is_array($user) ? ($user['username'] ?? 'User') : ($user->username ?? 'User');
            }

            try {
                if (!empty($toEmail) && filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                    $subject = 'تأیید حذف حساب کاربری چرتکه';
                    $fullName = $toName ?: 'کاربر گرامی';
                    $delReason = $reason ?? 'درخواست شما';
                    $delDate = date('Y-m-d H:i:s');
                    
                    $bodyHtml = "
                        <p>کاربر گرامی <strong>{$fullName}</strong>،</p>
                        <p>حساب کاربری شما در سیستم چرتکه با موفقیت و به طور کامل حذف گردید.</p>
                        <p>علت حذف: {$delReason}</p>
                        <p>زمان حذف: {$delDate}</p>
                        <p>با آرزوی موفقیت،<br>تیم پشتیبانی چرتکه</p>
                    ";
                    
                    $queue = new \Core\Queue($this->db);
                    $queue->push(\App\Jobs\SendEmailJob::class, [
                        'to_email'  => $toEmail,
                        'to_name'   => $toName,
                        'subject'   => $subject,
                        'body_html' => $bodyHtml
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->critical('account_deletion.email_failed', [
                    'user_id' => $userId,
                    'email' => $toEmail,
                    'error' => $e->getMessage()
                ]);
            }

            // 📢 شلیک رویداد حذف حساب برای سایر بخش‌های سیستم (معماری رویداد محور)
            try {
                $this->eventDispatcher->dispatchAsync('account.deleted', new \App\Events\AccountDeletedEvent(
                    $userId,
                    $user['email'] ?? 'unknown',
                    $reason ?? 'Unknown reason'
                ));
            } catch (\Throwable $e) {
                // عدم ایجاد وقفه در پروسه اصلی در صورت خطای رویداد
                $this->logger->error('account_deletion.event_dispatch_failed', ['error' => $e->getMessage()]);
            }

            $this->logger->warning('account_deletion.completed', [
                'user_id' => $userId,
                'username' => $user['username'],
                'reason' => $reason,
                'deleted_by' => $deletedBy
            ]);

            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->logger->error('account_deletion.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * بررسی اینکه حساب در انتظار حذف است یا نه
     */
    public function isPendingDeletion(int $userId): bool
    {
        $request = $this->deletionLogModel->getUserDeletionRequest($userId);
        return $request !== null && $request['status'] === 'requested';
    }

    /**
     * دریافت اطلاعات درخواست حذف
     */
    public function getDeletionRequest(int $userId): ?array
    {
        return $this->deletionLogModel->getUserDeletionRequest($userId);
    }

    /**
     * لغو درخواست حذف
     */
    public function cancelDeletion(int $userId): bool
    {
        try {
            if ($this->deletionLogModel->cancelDeletionRequest($userId)) {
                $this->logger->info('account_deletion.cancelled', ['user_id' => $userId]);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->logger->error('account_deletion.cancel_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت تاریخچه حذف‌ها برای ادمین
     */
    public function getDeletionHistory(int $limit = 50, int $offset = 0): array
    {
        // LOW-02: Bound pagination inputs to defend against excessive memory usage
        $safeLimit = max(1, min(250, $limit));
        $safeOffset = max(0, $offset);

        return $this->deletionLogModel->getDeletionHistory($safeLimit, $safeOffset);
    }
}
