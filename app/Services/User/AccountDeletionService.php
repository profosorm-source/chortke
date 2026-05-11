<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\AccountDeletionLog;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Services\CustomTaskService;

/**
 * AccountDeletionService — حذف حساب کاربران
 */
class AccountDeletionService extends \App\Services\BaseService
{
    private User $userModel;
    private AccountDeletionLog $deletionLogModel;
    private Database $db;
    private CustomTaskService $customTaskService;

    public function __construct(
        User $userModel,
        AccountDeletionLog $deletionLogModel,
        Database $db,
        LoggerInterface $logger,
        CustomTaskService $customTaskService
    ) {
        parent::__construct($logger);
        $this->userModel = $userModel;
        $this->deletionLogModel = $deletionLogModel;
        $this->db = $db;
        $this->customTaskService = $customTaskService;
    }

    /**
     * حذف خودکار درخواست‌های منقضی
     * این متد باید توسط Cron Job هر روز اجرا شود
     */
    public function processExpiredDeletionRequests(): int
    {
        try {
            $expiredRequests = $this->deletionLogModel->getExpiredDeletionRequests();
            $deletedCount = 0;

            foreach ($expiredRequests as $request) {
                if ($this->deleteUserAccount($request['user_id'], 'Automated deletion after 7-day period')) {
                    $deletedCount++;
                }
            }

            $this->logger->info('account_deletion.automated_completed', ['count' => $deletedCount]);
            return $deletedCount;
        } catch (\Exception $e) {
            $this->logger->error('account_deletion.automated_failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * حذف کامل حساب کاربر
     */
    public function deleteUserAccount(int $userId, ?string $reason = null, ?int $deletedBy = null): bool
    {
        $this->db->beginTransaction();

        try {
            $user = $this->userModel->findById($userId);
            if (!$user) {
                $this->logger->warning('account_deletion.user_not_found', ['user_id' => $userId]);
                return false;
            }

            // ۰. لغو تسک‌های فعال قبل از حذف مستقیم (Issue #23)
            $this->customTaskService->cancelActiveTasksForUser($userId);

            // ۱. حذف تراکنش‌های کاربر (ثبت شامل)
            $this->db->query("UPDATE transactions SET user_id = NULL, deleted_user_id = ? WHERE user_id = ?", [$userId, $userId]);

            // ۲. حذف وظایف کاربر
            $this->db->query("DELETE FROM custom_task_submissions WHERE user_id = ?", [$userId]);
            $this->db->query("DELETE FROM custom_tasks WHERE user_id = ?", [$userId]);

            // ۳. حذف اعلان‌ها
            $this->db->query("DELETE FROM notifications WHERE user_id = ?", [$userId]);

            // ۴. حذف تنظیمات
            $this->db->query("DELETE FROM user_settings WHERE user_id = ?", [$userId]);

            // ۵. حذف KYC
            $this->db->query("DELETE FROM kyc_verifications WHERE user_id = ?", [$userId]);

            // ۶. حذف کارت‌های بانکی
            $this->db->query("DELETE FROM user_bank_cards WHERE user_id = ?", [$userId]);

            // ۷. حذف سشن‌ها
            $this->db->query("DELETE FROM user_sessions WHERE user_id = ?", [$userId]);

            // ۸. حذف تنظیمات دو فاکتور
            $this->db->query("DELETE FROM two_factor_codes WHERE user_id = ?", [$userId]);

            // ۹. ثبت در account_deletion_logs
            $this->deletionLogModel->recordDeletion($userId, $deletedBy, $reason);

            // ۱۰. حذف کاربر (soft delete یا hard delete)
            // استفاده از UNIX_TIMESTAMP و LEFT برای جلوگیری از collision در ستون‌های Unique (Issue #22)
            $this->db->query(
                "UPDATE users SET 
                    deleted_at = NOW(),
                    email = LEFT(CONCAT(email, '_del_', UNIX_TIMESTAMP()), 100),
                    username = LEFT(CONCAT(username, '_del_', UNIX_TIMESTAMP()), 50),
                    status = 'deleted'
                WHERE id = ?",
                [$userId]
            );

            $this->db->commit();

            // 📢 شلیک رویداد حذف حساب برای سایر بخش‌های سیستم (معماری رویداد محور)
            try {
                \Core\EventDispatcher::getInstance()->dispatch('account.deleted', new \App\Events\AccountDeletedEvent(
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
        return $this->deletionLogModel->getDeletionHistory($limit, $offset);
    }
}
