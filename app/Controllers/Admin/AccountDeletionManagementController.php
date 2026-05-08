<?php

namespace App\Controllers\Admin;

use App\Services\User\UserService;
use App\Models\AccountDeletionLog;
use App\Services\User\AccountDeletionService;
use Core\Logger;

/**
 * Controller: AccountDeletionManagementController
 * صفحه مدیریت درخواست‌های حذف حساب از طرف Admin
 */
class AccountDeletionManagementController
{
    private UserService $userService;
    private AccountDeletionLog $deletionLogModel;
    private AccountDeletionService $deletionService;
    private Logger $logger;

    public function __construct(
        UserService $userService,
        AccountDeletionLog $deletionLogModel,
        AccountDeletionService $deletionService,
        Logger $logger
    ) {
        $this->userService = $userService;
        $this->deletionLogModel = $deletionLogModel;
        $this->deletionService = $deletionService;
        $this->logger = $logger;
    }

    /**
     * نمایش درخواست‌های حذف معلق
     */
    public function pending()
    {
        try {
            // گرفتن تمام درخواست‌های معلق
            $pendingDeletions = $this->deletionLogModel->getPendingDeletions();

            // تحریک تازه‌سازی صفحه
            $data = [
                'pending_deletions' => $pendingDeletions,
                'total_count' => count($pendingDeletions),
            ];

            view('admin/account-deletion/pending', $data);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.pending.failed', [
                'error' => $e->getMessage()
            ]);
            flash('خطا: دریافت درخواست‌های معلق ناموفق بود', 'error');
            redirect('/admin/dashboard');
        }
    }

    /**
     * نمایش تاریخچه حذف‌شده‌ها
     */
    public function history()
    {
        try {
            // گرفتن تاریخچه حذف‌شده‌ها
            $deletedAccounts = $this->deletionLogModel->getDeletedAccounts();

            $data = [
                'deleted_accounts' => $deletedAccounts,
                'total_count' => count($deletedAccounts),
            ];

            view('admin/account-deletion/history', $data);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.history.failed', [
                'error' => $e->getMessage()
            ]);
            flash('خطا: دریافت تاریخچه ناموفق بود', 'error');
            redirect('/admin/dashboard');
        }
    }

    public function stats()
    {
        try {
            $pending = $this->deletionLogModel->getPendingDeletions();
            $deleted = $this->deletionLogModel->getDeletedAccounts();

            $totalDataSize = 0;
            foreach ($pending as $deletion) {
                $totalDataSize += 1024 * 1024; // Base estimate 1MB per user
            }

            view('admin/account-deletion/stats', [
                'stats' => [
                    'pending_count' => count($pending),
                    'deleted_count' => count($deleted),
                    'total_data_size' => $this->formatBytes($totalDataSize),
                    'expiring_soon' => count(array_filter($pending, function($d) {
                        return strtotime($d['expires_at']) - time() < 86400;
                    })),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.stats.failed', ['error' => $e->getMessage()]);
            flash('خطا: دریافت آمار ناموفق بود', 'error');
            redirect('/admin/dashboard');
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * حذف فوری (بدون انتظار ۷ روز)
     */
    public function forceDelete()
    {
        try {
            $userId = $_POST['user_id'] ?? null;

            if (!$userId) {
                flash('شناسه کاربر الزامی است', 'error');
                redirect('/admin/account-deletion/pending');
                return;
            }

            // بررسی وجود درخواست
            $deletion = $this->deletionLogModel->getUserDeletionRequest($userId);
            if (!$deletion) {
                flash('درخواست حذف برای این کاربر یافت نشد', 'error');
                redirect('/admin/account-deletion/pending');
                return;
            }

            // حذف فوری
            $this->deletionService->deleteUserAccount($userId);

            $this->logger->info('admin.account_deletion.force_deleted', [
                'user_id' => $userId,
                'admin_id' => auth()->id()
            ]);

            flash('حساب کاربری با موفقیت حذف شد', 'success');
            redirect('/admin/account-deletion/history');

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.force_delete.failed', [
                'error' => $e->getMessage(),
                'user_id' => $_POST['user_id'] ?? null
            ]);
            flash('خطا: حذف ناموفق بود', 'error');
            redirect('/admin/account-deletion/pending');
        }
    }

    /**
     * لغو درخواست حذف
     */
    public function cancelDeletion()
    {
        try {
            $userId = $_POST['user_id'] ?? null;

            if (!$userId) {
                flash('شناسه کاربر الزامی است', 'error');
                redirect('/admin/account-deletion/pending');
                return;
            }

            // لغو درخواست
            $this->deletionService->cancelDeletion($userId);

            $this->logger->info('admin.account_deletion.cancelled', [
                'user_id' => $userId,
                'admin_id' => auth()->id()
            ]);

            flash('درخواست حذف با موفقیت لغو شد', 'success');
            redirect('/admin/account-deletion/pending');

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.cancel.failed', [
                'error' => $e->getMessage()
            ]);
            flash('خطا: لغو ناموفق بود', 'error');
            redirect('/admin/account-deletion/pending');
        }
    }

    /**
     * دریافت جزئیات کاربر برای حذف
     */
    public function getUserDetails()
    {
        try {
            $userId = $_GET['user_id'] ?? null;

            if (!$userId) {
                response_json(['success' => false, 'error' => 'شناسه کاربر الزامی است'], 400);
                return;
            }

            $user = $this->userService->find($userId);
            if (!$user) {
                response_json(['success' => false, 'error' => 'کاربر یافت نشد'], 404);
                return;
            }

            $deletion = $this->deletionLogModel->getUserDeletionRequest($userId);

            response_json([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'] ?? $user['email'],
                    'email' => $user['email'],
                    'created_at' => $user['created_at'],
                    'last_activity' => $user['last_activity_at'] ?? 'N/A'
                ],
                'deletion' => $deletion ? [
                    'requested_at' => $deletion['requested_at'],
                    'expires_at' => $deletion['expires_at'],
                    'status' => $deletion['status'],
                    'reason' => $deletion['reason'] ?? ''
                ] : null
            ]);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.get_details.failed', [
                'error' => $e->getMessage()
            ]);
            response_json(['success' => false, 'error' => 'خطا: دریافت اطلاعات ناموفق'], 500);
        }
    }

    /**
     * دریافت آمار حذف
     */
    public function getStats()
    {
        try {
            $pending = $this->deletionLogModel->getPendingDeletions();
            $deleted = $this->deletionLogModel->getDeletedAccounts();

            $totalDataSize = count($pending) * 1024 * 1024; // Base estimate 1MB per user

            response_json([
                'success' => true,
                'stats' => [
                    'pending_count' => count($pending),
                    'deleted_count' => count($deleted),
                    'total_data_size' => $this->formatBytes($totalDataSize),
                    'expiring_soon' => count(array_filter($pending, function($d) {
                        return strtotime($d['expires_at']) - time() < 86400; // 1 روز باقی‌ مانده
                    }))
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('admin.account_deletion.get_stats.failed', [
                'error' => $e->getMessage()
            ]);
            response_json(['success' => false, 'error' => 'خطا: دریافت آمار ناموفق'], 500);
        }
    }

    /**
     * محاسبه حجم داده‌های کاربر
     */
    private function calculateUserDataSize($user)
    {
        $size = 0;

        // تقریبی: هر کاربر تقریباً ۱-۵ مگابایت
        $size += 1024 * 1024; // Base: 1MB

        // بر اساس فعالیت بیشتر
        if (isset($user['transactions_count'])) {
            $size += $user['transactions_count'] * 1024; // هر تراکنش ≈ 1KB
        }

        return $size;
    }

    /**
     * تبدیل بایت به فرمت خوانا
     */
   
}
