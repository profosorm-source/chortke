<?php

/**
 * مسیرهای پنل مدیریت
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Middleware\CSRFMiddleware;
use App\Middleware\PermissionMiddleware;
use App\Controllers\Admin\AuthController          as AdminAuthController;
use App\Controllers\Admin\DashboardController     as AdminDashboardController;
use App\Controllers\Admin\UserController          as AdminUserController;
use App\Controllers\Admin\KYCController           as AdminKYCController;
use App\Controllers\Admin\LogController           as AdminLogController;
use App\Controllers\Admin\NotificationController  as AdminNotificationController;
use App\Controllers\Admin\BankCardController      as AdminBankCardController;
use App\Controllers\Admin\ManualDepositController as AdminManualDepositController;
use App\Controllers\Admin\CryptoDepositController as AdminCryptoDepositController;
use App\Controllers\Admin\WithdrawalController    as AdminWithdrawalController;
use App\Controllers\Admin\TransactionController   as AdminTransactionController;
use App\Controllers\Admin\OnlinePaymentController as AdminOnlinePaymentController;
use App\Controllers\Admin\SocialAccountController as AdminSocialAccountController;
use App\Controllers\Admin\AdTaskController        as AdminAdTaskController;
use App\Controllers\Admin\ExecutorTaskController  as AdminExecutorTaskController;
use App\Controllers\Admin\RoleController          as AdminRoleController;
use App\Controllers\Admin\ReferralController      as AdminReferralController;
use App\Controllers\Admin\LevelController         as AdminLevelController;
use App\Controllers\Admin\InfluencerController     as AdminInfluencerController;
use App\Controllers\Admin\ContentController       as AdminContentController;
use App\Controllers\Admin\InvestmentController    as AdminInvestmentController;
use App\Controllers\Admin\LotteryController       as AdminLotteryController;
use App\Controllers\Admin\BannerController        as AdminBannerController;
use App\Controllers\Admin\BugReportController     as AdminBugReportController;
use App\Controllers\Admin\KpiController;
use App\Controllers\Admin\SystemSettingController;
use App\Controllers\Admin\TicketController        as AdminTicketController;
use App\Controllers\Admin\CouponController        as AdminCouponController;
use App\Controllers\Admin\FraudController;
use App\Controllers\Admin\FraudDashboardController;
use App\Controllers\Admin\FraudManagementController;

use App\Controllers\Admin\AuditTrailController;
use App\Controllers\Admin\AdminExportController;
use App\Controllers\Admin\FeatureFlagController;
use App\Controllers\SearchController;
use App\Controllers\Admin\RiskPolicyController;
use App\Controllers\Admin\ScoreManagementController;
use App\Controllers\Admin\SentryAdminController;
use App\Controllers\Admin\SocialTaskController as AdminSocialTaskController;
use App\Controllers\Admin\MessageModerationController;
use App\Controllers\Admin\AccountDeletionManagementController;
use App\Controllers\Admin\BackupManagementController;
use App\Controllers\Admin\AdminAnalyticsController;

$admin = [AuthMiddleware::class, AdminMiddleware::class, CSRFMiddleware::class];
$r     = app()->router;

// ── ورود/خروج ──────────────────────────────────────────────────────────────
$r->get('/admin/login',   [AdminAuthController::class, 'showLogin']);
$r->post('/admin/login',  [AdminAuthController::class, 'login'], [CSRFMiddleware::class]);
$r->get('/admin/verify-2fa',  [AdminAuthController::class, 'showVerify2FA']);
$r->post('/admin/verify-2fa', [AdminAuthController::class, 'verify2FA'], [CSRFMiddleware::class]);
$r->post('/admin/logout', [AdminAuthController::class, 'logout'], [AuthMiddleware::class, AdminMiddleware::class, CSRFMiddleware::class]);

// ── داشبورد ────────────────────────────────────────────────────────────────
$r->get('/admin/dashboard',                   [AdminDashboardController::class, 'index'],          $admin);
$r->get('/admin/dashboard/recent-activity',   [AdminDashboardController::class, 'recentActivity'], $admin);
$r->get('/admin/dashboard/system-status',     [AdminDashboardController::class, 'systemStatus'],   $admin);

// ── کاربران ────────────────────────────────────────────────────────────────
$r->get('/admin/users',                [AdminUserController::class, 'index'],      array_merge($admin, [PermissionMiddleware::class . ':user.manage.view']));
$r->get('/admin/users/create',         [AdminUserController::class, 'create'],     array_merge($admin, [PermissionMiddleware::class . ':user.manage.edit']));
$r->post('/admin/users/store',         [AdminUserController::class, 'store'],      array_merge($admin, [PermissionMiddleware::class . ':user.manage.edit']));
$r->get('/admin/users/{id}/edit',      [AdminUserController::class, 'edit'],       array_merge($admin, [PermissionMiddleware::class . ':user.manage.edit']));
$r->post('/admin/users/{id}/update',   [AdminUserController::class, 'update'],     array_merge($admin, [PermissionMiddleware::class . ':user.manage.edit']));
$r->post('/admin/users/{id}/ban',      [AdminUserController::class, 'ban'],        array_merge($admin, [PermissionMiddleware::class . ':user.manage.ban']));
$r->post('/admin/users/{id}/unban',    [AdminUserController::class, 'unban'],      array_merge($admin, [PermissionMiddleware::class . ':user.manage.ban']));
$r->post('/admin/users/{id}/suspend',  [AdminUserController::class, 'suspend'],    array_merge($admin, [PermissionMiddleware::class . ':user.manage.ban']));
$r->post('/admin/users/{id}/unsuspend',[AdminUserController::class, 'unsuspend'],  array_merge($admin, [PermissionMiddleware::class . ':user.manage.ban']));

// ── KYC ────────────────────────────────────────────────────────────────────
$r->get('/admin/kyc',                       [AdminKYCController::class, 'index'],          $admin);
$r->get('/admin/kyc/review/{id}',           [AdminKYCController::class, 'review'],         $admin);
$r->post('/admin/kyc/verify/{id}',          [AdminKYCController::class, 'verify'],         $admin);
$r->post('/admin/kyc/reject/{id}',          [AdminKYCController::class, 'reject'],         $admin);
$r->post('/admin/kyc/mark-reviewing/{id}',  [AdminKYCController::class, 'markAsReviewing'],$admin);
$r->post('/admin/kyc/delete-image/{id}',    [AdminKYCController::class, 'deleteImage'],    $admin);

// لاگ‌ها
$r->get('/admin/logs',             [AdminLogController::class, 'index'],         $admin);
$r->post('/admin/logs/cleanup',    [AdminLogController::class, 'cleanup'],       $admin);

// سیستم لاگ پیشرفته
// سیستم لاگ پیشرفته
$r->get('/admin/logs/dashboard',             [AdminLogController::class, 'dashboard'],           $admin);
$r->get('/admin/logs/errors',                [AdminLogController::class, 'errors'],              $admin);
$r->get('/admin/logs/error-details',         [AdminLogController::class, 'errorDetails'],        $admin);
$r->post('/admin/logs/resolve-error',        [AdminLogController::class, 'resolveError'],         $admin);
$r->get('/admin/logs/notification-settings', [AdminLogController::class, 'notificationSettings'], $admin);
$r->post('/admin/logs/save-channel',         [AdminLogController::class, 'saveChannel'],         $admin);
$r->post('/admin/logs/test-channel',         [AdminLogController::class, 'testChannel'],         $admin);
$r->get('/admin/logs/api-stats',             [AdminLogController::class, 'apiStats'],            $admin);

// ── اعلان‌ها ────────────────────────────────────────────────────────────────
$r->get('/admin/notifications',                  [AdminNotificationController::class, 'index'],             $admin);
$r->get('/admin/notifications/unread-count',     [AdminNotificationController::class, 'unreadCount'],       $admin);
$r->get('/admin/notifications/fetch',            [AdminNotificationController::class, 'fetch'],             $admin);
$r->get('/admin/notifications/send',             [AdminNotificationController::class, 'showSend'],          $admin);
$r->post('/admin/notifications/send',            [AdminNotificationController::class, 'send'],              $admin);
$r->get('/admin/notifications/stats',            [AdminNotificationController::class, 'stats'],             $admin);
$r->get('/admin/notifications/stats/fetch',      [AdminNotificationController::class, 'statsFetch'],        $admin);
$r->get('/admin/notifications/templates',        [AdminNotificationController::class, 'templates'],         $admin);
$r->post('/admin/notifications/templates/save',  [AdminNotificationController::class, 'saveTemplate'],      $admin);
$r->post('/admin/notifications/templates/delete',[AdminNotificationController::class, 'deleteTemplate'],    $admin);
$r->post('/admin/notifications/mark-read/{id}',  [AdminNotificationController::class, 'markAsRead'],        $admin);
$r->post('/admin/notifications/mark-all-read',   [AdminNotificationController::class, 'markAllAsRead'],     $admin);

// ── کارت‌های بانکی ──────────────────────────────────────────────────────────
$r->get('/admin/bank-cards',         [AdminBankCardController::class, 'index'],  array_merge($admin, [PermissionMiddleware::class . ':finance.card.view']));
$r->get('/admin/bank-cards/review',  [AdminBankCardController::class, 'review'], array_merge($admin, [PermissionMiddleware::class . ':finance.card.view']));
$r->post('/admin/bank-cards/verify', [AdminBankCardController::class, 'verify'], array_merge($admin, [PermissionMiddleware::class . ':finance.card.approve']));
$r->post('/admin/bank-cards/reject', [AdminBankCardController::class, 'reject'], array_merge($admin, [PermissionMiddleware::class . ':finance.card.approve']));

// ── واریز دستی ─────────────────────────────────────────────────────────────
$r->get('/admin/manual-deposits',         [AdminManualDepositController::class, 'index'],  array_merge($admin, [PermissionMiddleware::class . ':finance.deposit.view']));
$r->get('/admin/manual-deposits/review',  [AdminManualDepositController::class, 'review'], array_merge($admin, [PermissionMiddleware::class . ':finance.deposit.view']));
$r->post('/admin/manual-deposits/verify', [AdminManualDepositController::class, 'verify'], array_merge($admin, [PermissionMiddleware::class . ':finance.deposit.approve']));
$r->post('/admin/manual-deposits/reject', [AdminManualDepositController::class, 'reject'], array_merge($admin, [PermissionMiddleware::class . ':finance.deposit.approve']));

// ── واریز کریپتو ───────────────────────────────────────────────────────────
$r->get('/admin/crypto-deposits',         [AdminCryptoDepositController::class, 'index'],  array_merge($admin, [PermissionMiddleware::class . ':finance.crypto.view']));
$r->get('/admin/crypto-deposits/review',  [AdminCryptoDepositController::class, 'review'], array_merge($admin, [PermissionMiddleware::class . ':finance.crypto.view']));
$r->post('/admin/crypto-deposits/verify', [AdminCryptoDepositController::class, 'verify'], array_merge($admin, [PermissionMiddleware::class . ':finance.crypto.approve']));
$r->post('/admin/crypto-deposits/reject', [AdminCryptoDepositController::class, 'reject'], array_merge($admin, [PermissionMiddleware::class . ':finance.crypto.approve']));

// ── برداشت‌ها ────────────────────────────────────────────────────────────────
$r->get('/admin/withdrawals',         [AdminWithdrawalController::class, 'index'],   array_merge($admin, [PermissionMiddleware::class . ':finance.withdrawal.view']));
$r->get('/admin/withdrawals/review',  [AdminWithdrawalController::class, 'review'],  array_merge($admin, [PermissionMiddleware::class . ':finance.withdrawal.view']));
$r->post('/admin/withdrawals/process',[AdminWithdrawalController::class, 'process'], array_merge($admin, [PermissionMiddleware::class . ':finance.withdrawal.approve']));
$r->post('/admin/withdrawals/reject', [AdminWithdrawalController::class, 'reject'],  array_merge($admin, [PermissionMiddleware::class . ':finance.withdrawal.approve']));

// ── تراکنش‌ها ────────────────────────────────────────────────────────────────
$r->get('/admin/transactions',       [AdminTransactionController::class, 'index'], array_merge($admin, [PermissionMiddleware::class . ':finance.transaction.view']));
$r->get('/admin/transactions/show',  [AdminTransactionController::class, 'show'],  array_merge($admin, [PermissionMiddleware::class . ':finance.transaction.view']));

// ── بررسی پرداخت‌های آنلاین معلق ─────────────────────────────────────────────
$r->get('/admin/gateway-payments',         [AdminOnlinePaymentController::class, 'index'],  array_merge($admin, [PermissionMiddleware::class . ':finance.transaction.view']));
$r->post('/admin/gateway-payments/verify', [AdminOnlinePaymentController::class, 'verify'], array_merge($admin, [PermissionMiddleware::class . ':finance.transaction.approve']));

// ── حساب‌های اجتماعی ────────────────────────────────────────────────────────
$r->get('/admin/social-accounts',               [AdminSocialAccountController::class, 'index'],  $admin);
$r->get('/admin/social-accounts/{id}',          [AdminSocialAccountController::class, 'show'],   $admin);
$r->post('/admin/social-accounts/{id}/verify',  [AdminSocialAccountController::class, 'verify'], $admin);
$r->post('/admin/social-accounts/{id}/reject',  [AdminSocialAccountController::class, 'reject'], $admin);

// ─────────────────────────────────────────────────
// Admin Routes (routes/admin.php)
// ─────────────────────────────────────────────────

// لیست همه وظایف
$r->get('/admin/custom-tasks', [AdminAdTaskController::class, 'index'], array_merge($admin, [PermissionMiddleware::class . ':tasks.view']));

// جزئیات وظیفه
$r->get('/admin/custom-tasks/{id}', [AdminAdTaskController::class, 'show'], array_merge($admin, [PermissionMiddleware::class . ':tasks.view']));

// تایید/رد وظیفه (Ajax)
$r->post('/admin/custom-tasks/approve', [AdminAdTaskController::class, 'approve'], array_merge($admin, [PermissionMiddleware::class . ':tasks.approve']));

// تایید/رد اجباری submission (Ajax)
$r->post('/admin/custom-tasks/submissions/force-approve', [AdminExecutorTaskController::class, 'forceApproveSubmission'], array_merge($admin, [PermissionMiddleware::class . ':tasks.manage']));
$r->post('/admin/custom-tasks/submissions/force-reject', [AdminExecutorTaskController::class, 'forceRejectSubmission'], array_merge($admin, [PermissionMiddleware::class . ':tasks.manage']));

// آمار و گزارش (Ajax)
$r->get('/admin/custom-tasks/stats', [AdminAdTaskController::class, 'stats'], array_merge($admin, [PermissionMiddleware::class . ':tasks.view']));
$r->post('/admin/custom-tasks/disputes/resolve',  [AdminExecutorTaskController::class, 'resolveDispute'], array_merge($admin, [PermissionMiddleware::class . ':tasks.manage']));

// Stale admin route definitions removed: task-execution, task-dispute, task-recheck, and SEO keyword management
// These controllers do not exist in app/Controllers/Admin and appear to be orphaned legacy routes.

// ── نقش‌ها ──────────────────────────────────────────────────────────────────
$r->get('/admin/roles',              [AdminRoleController::class, 'index'],  array_merge($admin, [PermissionMiddleware::class . ':roles.view']));
$r->get('/admin/roles/create',       [AdminRoleController::class, 'create'], array_merge($admin, [PermissionMiddleware::class . ':roles.manage']));
$r->post('/admin/roles/store',       [AdminRoleController::class, 'store'],  array_merge($admin, [PermissionMiddleware::class . ':roles.manage']));
$r->get('/admin/roles/{id}/edit',    [AdminRoleController::class, 'edit'],   array_merge($admin, [PermissionMiddleware::class . ':roles.manage']));
$r->post('/admin/roles/{id}/update', [AdminRoleController::class, 'update'], array_merge($admin, [PermissionMiddleware::class . ':roles.manage']));
$r->post('/admin/roles/{id}/delete', [AdminRoleController::class, 'delete'], array_merge($admin, [PermissionMiddleware::class . ':roles.manage']));
$r->post('/admin/roles/{id}/toggle', [AdminRoleController::class, 'toggle'], array_merge($admin, [PermissionMiddleware::class . ':roles.manage']));

// ── Referral ─────────────────────────────────────────────────────────────────
$r->get('/admin/referral',                    [AdminReferralController::class, 'index'],        array_merge($admin, [PermissionMiddleware::class . ':referrals.view']));
$r->get('/admin/referral/settings',           [AdminReferralController::class, 'settings'],     array_merge($admin, [PermissionMiddleware::class . ':referrals.manage']));
$r->post('/admin/referral/settings/save',     [AdminReferralController::class, 'saveSettings'], array_merge($admin, [PermissionMiddleware::class . ':referrals.manage']));
$r->get('/admin/referral/user/{id}',          [AdminReferralController::class, 'userDetail'],   array_merge($admin, [PermissionMiddleware::class . ':referrals.view']));
$r->post('/admin/referral/{id}/cancel',       [AdminReferralController::class, 'cancel'],       array_merge($admin, [PermissionMiddleware::class . ':referrals.manage']));
$r->post('/admin/referral/batch-pay',         [AdminReferralController::class, 'batchPay'],     array_merge($admin, [PermissionMiddleware::class . ':referrals.manage']));

// ── سطوح ────────────────────────────────────────────────────────────────────
$r->get('/admin/levels',                          [AdminLevelController::class, 'index'],           array_merge($admin, [PermissionMiddleware::class . ':settings.view']));
$r->get('/admin/levels/history',                  [AdminLevelController::class, 'history'],         array_merge($admin, [PermissionMiddleware::class . ':settings.view']));
$r->get('/admin/levels/create',                   [AdminLevelController::class, 'create'],          array_merge($admin, [PermissionMiddleware::class . ':settings.edit']));
$r->post('/admin/levels/create',                  [AdminLevelController::class, 'store'],           array_merge($admin, [PermissionMiddleware::class . ':settings.edit']));
$r->get('/admin/levels/{id}/edit',                [AdminLevelController::class, 'edit'],            array_merge($admin, [PermissionMiddleware::class . ':settings.edit']));
$r->post('/admin/levels/{id}/update',             [AdminLevelController::class, 'update'],          array_merge($admin, [PermissionMiddleware::class . ':settings.edit']));
$r->post('/admin/levels/{id}/delete',             [AdminLevelController::class, 'destroy'],         array_merge($admin, [PermissionMiddleware::class . ':settings.edit']));
$r->post('/admin/levels/change-user-level',       [AdminLevelController::class, 'changeUserLevel'], array_merge($admin, [PermissionMiddleware::class . ':users.edit']));

// ── استوری ──────────────────────────────────────────────────────────────────
$r->get('/admin/influencer/orders',                   [AdminInfluencerController::class, 'orders'],        array_merge($admin, [PermissionMiddleware::class . ':influencer.view']));
$r->get('/admin/influencer/profiles',                 [AdminInfluencerController::class, 'profiles'],      array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));
$r->post('/admin/influencer/profiles/approve',        [AdminInfluencerController::class, 'approveProfile'],array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));
$r->get('/admin/influencer/verifications',            [AdminInfluencerController::class, 'verificationRequests'], array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));
$r->post('/admin/influencer/verifications/approve',   [AdminInfluencerController::class, 'approveVerification'], array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));
$r->post('/admin/influencer/verifications/reject',    [AdminInfluencerController::class, 'rejectVerification'],  array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));
$r->get('/admin/influencer/disputes',                 [AdminInfluencerController::class, 'disputes'],      array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));
$r->get('/admin/influencer/disputes/{id}',            [AdminInfluencerController::class, 'disputeDetail'], array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));
$r->post('/admin/influencer/disputes/{id}/resolve',   [AdminInfluencerController::class, 'resolveDispute'],array_merge($admin, [PermissionMiddleware::class . ':influencer.manage']));

// ── محتوا ────────────────────────────────────────────────────────────────────
$r->get('/admin/content',                           [AdminContentController::class, 'index'],        $admin);
$r->get('/admin/content/revenues',                  [AdminContentController::class, 'revenues'],     $admin);
$r->get('/admin/content/{id}',                      [AdminContentController::class, 'show'],         $admin);
$r->post('/admin/content/{id}/approve',             [AdminContentController::class, 'approve'],      $admin);
$r->post('/admin/content/{id}/reject',              [AdminContentController::class, 'reject'],       $admin);
$r->post('/admin/content/{id}/publish',             [AdminContentController::class, 'publish'],      $admin);
$r->post('/admin/content/{id}/suspend',             [AdminContentController::class, 'suspend'],      $admin);
$r->get('/admin/content/{id}/revenue/create',       [AdminContentController::class, 'revenueCreate'],$admin);
$r->post('/admin/content/{id}/revenue/store',       [AdminContentController::class, 'revenueStore'], $admin);
$r->post('/admin/content/revenue/{rid}/approve',    [AdminContentController::class, 'revenueApprove'],$admin);
$r->post('/admin/content/revenue/{rid}/pay',        [AdminContentController::class, 'revenuePay'],   $admin);

// ── سرمایه‌گذاری ─────────────────────────────────────────────────────────────
$r->get('/admin/investment',                              [AdminInvestmentController::class, 'index'],         $admin);
$r->get('/admin/investment/solvency-report',              [AdminInvestmentController::class, 'solvencyReport'], $admin);
$r->get('/admin/investment/trades',                       [AdminInvestmentController::class, 'trades'],        $admin);
$r->get('/admin/investment/trades/create',                [AdminInvestmentController::class, 'tradeCreate'],   $admin);
$r->post('/admin/investment/trades/store',                [AdminInvestmentController::class, 'tradeStore'],    $admin);
$r->post('/admin/investment/trades/{id}/close',           [AdminInvestmentController::class, 'tradeClose'],    $admin);
$r->get('/admin/investment/apply-profit',                 [AdminInvestmentController::class, 'applyProfitForm'],$admin);
$r->post('/admin/investment/apply-profit',                [AdminInvestmentController::class, 'applyProfit'],   $admin);
$r->get('/admin/investment/withdrawals',                  [AdminInvestmentController::class, 'withdrawals'],   $admin);
$r->post('/admin/investment/withdrawals/{id}/approve',    [AdminInvestmentController::class, 'withdrawalApprove'],$admin);
$r->post('/admin/investment/withdrawals/{id}/reject',     [AdminInvestmentController::class, 'withdrawalReject'], $admin);
$r->get('/admin/investment/{id}',                         [AdminInvestmentController::class, 'show'],          $admin);
$r->post('/admin/investment/{id}/suspend',                [AdminInvestmentController::class, 'suspend'],       $admin);

// ── قرعه‌کشی ─────────────────────────────────────────────────────────────────
$r->get('/admin/lottery',                          [AdminLotteryController::class, 'index'],         $admin);
$r->get('/admin/lottery/create',                   [AdminLotteryController::class, 'create'],        $admin);
$r->post('/admin/lottery/store',                   [AdminLotteryController::class, 'store'],         $admin);
$r->get('/admin/lottery/{id}',                     [AdminLotteryController::class, 'show'],          $admin);
$r->post('/admin/lottery/{id}/generate-numbers',   [AdminLotteryController::class, 'generateNumbers'],$admin);
$r->post('/admin/lottery/daily/{did}/finalize',    [AdminLotteryController::class, 'finalizeDaily'], $admin);
$r->post('/admin/lottery/{id}/select-winner',      [AdminLotteryController::class, 'selectWinner'],  $admin);
$r->post('/admin/lottery/{id}/cancel',             [AdminLotteryController::class, 'cancel'],        $admin);

// ── بنرها ────────────────────────────────────────────────────────────────────
$r->get('/admin/banners',                          [AdminBannerController::class, 'index'],           $admin);
$r->get('/admin/banners/placements',               [AdminBannerController::class, 'placements'],      $admin);
$r->get('/admin/banners/create',                   [AdminBannerController::class, 'showCreate'],      $admin);
$r->post('/admin/banners/store',                   [AdminBannerController::class, 'store'],           $admin);
$r->get('/admin/banners/{id}/edit',                [AdminBannerController::class, 'showEdit'],        $admin);
$r->get('/admin/banners/{id}/stats',               [AdminBannerController::class, 'stats'],           $admin);
$r->post('/admin/banners/{id}/update',             [AdminBannerController::class, 'update'],          $admin);
$r->post('/admin/banners/{id}/delete',             [AdminBannerController::class, 'delete'],          $admin);
$r->post('/admin/banners/{id}/toggle',             [AdminBannerController::class, 'toggle'],          $admin);
$r->post('/admin/banners/placements/{id}/update',  [AdminBannerController::class, 'updatePlacement'], $admin);
$r->post('/admin/banners/placements/{id}/toggle',  [AdminBannerController::class, 'togglePlacement'], $admin);

// ── گزارش باگ ────────────────────────────────────────────────────────────────
$r->get('/admin/bug-reports',                  [AdminBugReportController::class, 'index'],           $admin);
$r->get('/admin/bug-reports/{id}',             [AdminBugReportController::class, 'show'],            $admin);
$r->post('/admin/bug-reports/{id}/status',     [AdminBugReportController::class, 'updateStatus'],    $admin);
$r->post('/admin/bug-reports/{id}/priority',   [AdminBugReportController::class, 'updatePriority'],  $admin);
$r->post('/admin/bug-reports/{id}/comment',    [AdminBugReportController::class, 'addComment'],      $admin);
$r->post('/admin/bug-reports/{id}/suspicious', [AdminBugReportController::class, 'toggleSuspicious'],$admin);
$r->post('/admin/bug-reports/{id}/delete',     [AdminBugReportController::class, 'delete'],          $admin);

// ── KPI ──────────────────────────────────────────────────────────────────────
$r->get('/admin/kpi',                       [KpiController::class, 'index'],               $admin);
$r->get('/admin/kpi/financial',             [KpiController::class, 'financial'],            $admin);
$r->get('/admin/kpi/users',                 [KpiController::class, 'users'],               $admin);
$r->get('/admin/kpi/chart-data',            [KpiController::class, 'chartData'],           $admin);
$r->get('/admin/kpi/export/users',          [KpiController::class, 'exportUsers'],         $admin);
$r->get('/admin/kpi/export/transactions',   [KpiController::class, 'exportTransactions'],  $admin);
$r->get('/admin/kpi/export/summary',        [KpiController::class, 'exportSummary'],       $admin);

// ── تنظیمات ──────────────────────────────────────────────────────────────────
$r->get('/admin/settings',               [SystemSettingController::class, 'index'],       array_merge($admin, [PermissionMiddleware::class . ':admin.view_settings']));
$r->post('/admin/settings/{id}/update',  [SystemSettingController::class, 'update'],      array_merge($admin, [PermissionMiddleware::class . ':admin.edit_settings']));
$r->post('/admin/settings/upload-image', [SystemSettingController::class, 'uploadImage'], array_merge($admin, [PermissionMiddleware::class . ':admin.edit_settings']));
$r->post('/admin/settings/remove-image', [SystemSettingController::class, 'removeImage'], array_merge($admin, [PermissionMiddleware::class . ':admin.edit_settings']));

// ── صفحات استاتیک ─────────────────────────────────────────────────────────────
// Stale admin legacy page management routes removed: Admin\PageController does not exist.

// ── تیکت‌ها (ادمین فقط) ───────────────────────────────────────────────────────────────────
$r->get('/admin/tickets',                  [AdminTicketController::class, 'index'],        $admin);
$r->get('/admin/tickets/show/{id}',        [AdminTicketController::class, 'show'],         $admin);
$r->post('/admin/tickets/reply',           [AdminTicketController::class, 'reply'],        $admin);
$r->post('/admin/tickets/change-status',   [AdminTicketController::class, 'changeStatus'], $admin);
$r->post('/admin/tickets/assign',          [AdminTicketController::class, 'assign'],       $admin);

// ── کوپن ─────────────────────────────────────────────────────────────────────
$r->get('/admin/coupons',                  [AdminCouponController::class, 'index'],       $admin);
$r->get('/admin/coupons/create',           [AdminCouponController::class, 'create'],      $admin);
$r->get('/admin/coupons/redemptions',      [AdminCouponController::class, 'redemptions'], $admin);
$r->get('/admin/coupons/statistics',       [AdminCouponController::class, 'statistics'],  $admin);
$r->post('/admin/coupons/store',           [AdminCouponController::class, 'store'],       $admin);
$r->post('/admin/coupons/delete',          [AdminCouponController::class, 'delete'],      $admin);
$r->post('/admin/coupons/toggle-active',   [AdminCouponController::class, 'toggleActive'],$admin);
$r->get('/admin/coupons/{id}/edit',        [AdminCouponController::class, 'edit'],        $admin);
$r->post('/admin/coupons/{id}/update',     [AdminCouponController::class, 'update'],      $admin);
$r->get('/admin/coupons/{id}',             [AdminCouponController::class, 'details'],     $admin);

// ── تقلب ─────────────────────────────────────────────────────────────────────
$r->get('/admin/fraud/dashboard',          [FraudDashboardController::class,  'index'],         $admin);
$r->get('/admin/fraud/logs',               [FraudManagementController::class, 'fraudLogs'],     $admin);
$r->get('/admin/fraud/ip-blacklist',       [FraudManagementController::class, 'ipBlacklist'],   $admin);
$r->post('/admin/fraud/ip-block',          [FraudManagementController::class, 'blockIP'],       $admin);
$r->post('/admin/fraud/ip-unblock',        [FraudManagementController::class, 'unblockIP'],     $admin);
$r->get('/admin/fraud/device-blacklist',   [FraudManagementController::class, 'deviceBlacklist'],$admin);
$r->post('/admin/fraud/device-block',      [FraudManagementController::class, 'blockDevice'],   $admin);
$r->post('/admin/fraud/device-unblock',    [FraudManagementController::class, 'unblockDevice'], $admin);
$r->post('/admin/fraud/reset-score',       [FraudManagementController::class, 'resetFraudScore'],$admin);

// ── Feature Flags ─────────────────────────────────────────────────────────────
$r->get('/admin/features',             [FeatureFlagController::class, 'index'],         $admin);
$r->post('/admin/features/toggle',     [FeatureFlagController::class, 'toggle'],        $admin);
$r->post('/admin/features/create',     [FeatureFlagController::class, 'create'],        $admin);
$r->post('/admin/features/update',     [FeatureFlagController::class, 'update'],        $admin);
$r->post('/admin/features/advanced-update', [FeatureFlagController::class, 'advancedUpdate'], $admin);
$r->post('/admin/features/delete',     [FeatureFlagController::class, 'delete'],        $admin);
$r->get('/admin/features/stats',       [FeatureFlagController::class, 'getStats'],      $admin);
$r->get('/admin/features/metrics/{name}', [FeatureFlagController::class, 'getMetrics'], $admin);
$r->get('/admin/features/history/{name}', [FeatureFlagController::class, 'getHistory'], $admin);

// ── Audit Trail ───────────────────────────────────────────────────────────────
$r->get('/admin/audit-trail',        [AuditTrailController::class, 'index'],  $admin);
$r->get('/admin/audit-trail/export', [AuditTrailController::class, 'export'], $admin);

// ── Export ────────────────────────────────────────────────────────────────────
$r->get('/admin/export',              [AdminExportController::class, 'index'],        $admin);
$r->get('/admin/export/users',        [AdminExportController::class, 'users'],        $admin);
$r->get('/admin/export/transactions', [AdminExportController::class, 'transactions'], $admin);
$r->get('/admin/export/withdrawals',  [AdminExportController::class, 'withdrawals'],  $admin);
$r->get('/admin/export/audit-trail',  [AdminExportController::class, 'auditTrail'],   $admin);
// ── Account Deletion Management (Phase 5e) ─────────────────────────────────
$r->get('/admin/account-deletion/pending',      [AccountDeletionManagementController::class, 'pending'],      $admin);
$r->get('/admin/account-deletion/history',      [AccountDeletionManagementController::class, 'history'],      $admin);
$r->post('/admin/account-deletion/force-delete',[AccountDeletionManagementController::class, 'forceDelete'],  $admin);
$r->post('/admin/account-deletion/cancel',      [AccountDeletionManagementController::class, 'cancelDeletion'], $admin);
$r->get('/admin/account-deletion/user-details', [AccountDeletionManagementController::class, 'getUserDetails'], $admin);
$r->get('/admin/account-deletion/stats',        [AccountDeletionManagementController::class, 'getStats'],      $admin);

// ── Backup Management (Phase 5e) ───────────────────────────────────────────
$r->get('/admin/backups',                       [BackupManagementController::class, 'index'],           $admin);
$r->post('/admin/backups/create',               [BackupManagementController::class, 'createBackup'],   $admin);
$r->post('/admin/backups/{id}/restore',         [BackupManagementController::class, 'restoreBackup'],  $admin);
$r->post('/admin/backups/{id}/verify',          [BackupManagementController::class, 'verifyBackup'],   $admin);
$r->get('/admin/backups/stats',                 [BackupManagementController::class, 'stats'],          $admin);
$r->post('/admin/backups/cleanup',              [BackupManagementController::class, 'cleanup'],        $admin);
// ── جستجو ─────────────────────────────────────────────────────────────────────
$r->get('/admin/search', [SearchController::class, 'adminSearch'], $admin);

// Risk policies
$r->get('/admin/risk-policies',              [RiskPolicyController::class, 'index'], $admin);
$r->post('/admin/risk-policies/update',      [RiskPolicyController::class, 'update'], $admin);

// User score management
$r->get('/admin/users/{id}/scores',                 [ScoreManagementController::class, 'showUserScores'], $admin);
$r->post('/admin/users/{id}/scores/adjust',         [ScoreManagementController::class, 'adjustScore'], $admin);
$r->post('/admin/scores/adjustments/{id}/revoke',   [ScoreManagementController::class, 'revokeAdjustment'], $admin);
$r->get('/admin/users/{id}/scores/history',         [ScoreManagementController::class, 'history'], $admin);

// ── مانیتورینگ سیستم (Sentry) ────────────────────────────────────────
$r->get('/admin/sentry',                              [SentryAdminController::class, 'index'],            $admin);
$r->get('/admin/sentry/issues',                       [SentryAdminController::class, 'issues'],           $admin);
$r->get('/admin/sentry/failed-jobs',                   [SentryAdminController::class, 'failedJobs'],       $admin);
$r->get('/admin/sentry/outbox-dlq',                    [SentryAdminController::class, 'outboxDLQ'],       $admin);
$r->get('/admin/sentry/failed-jobs/{id}',              [SentryAdminController::class, 'failedJobDetails'], $admin);
$r->get('/admin/sentry/issues/{id}',                  [SentryAdminController::class, 'issueDetails'],     $admin);
$r->get('/admin/sentry/performance',                  [SentryAdminController::class, 'performance'],      $admin);
$r->get('/admin/sentry/analytics',                    [SentryAdminController::class, 'analytics'],        $admin);
$r->get('/admin/sentry/alerts',                       [SentryAdminController::class, 'alerts'],           $admin);
$r->get('/admin/sentry/audit',                        [SentryAdminController::class, 'auditTrail'],       $admin);

// Sentry API endpoints
$r->post('/admin/sentry/issues/{id}/resolve',         [SentryAdminController::class, 'resolveIssue'],     $admin);
$r->post('/admin/sentry/issues/{id}/mute',            [SentryAdminController::class, 'muteIssue'],        $admin);
$r->post('/admin/sentry/failed-jobs/{id}/retry',      [SentryAdminController::class, 'retryFailedJob'],   $admin);
$r->post('/admin/sentry/failed-jobs/{id}/forget',     [SentryAdminController::class, 'forgetFailedJob'],  $admin);
$r->post('/admin/sentry/alerts/{id}/acknowledge',     [SentryAdminController::class, 'acknowledgeAlert'], $admin);
$r->get('/admin/sentry/api/chart-data',               [SentryAdminController::class, 'getChartData'],     $admin);
$r->get('/admin/sentry/api/health',                   [SentryAdminController::class, 'healthCheck'],      $admin);
$r->post('/admin/sentry/audit/export',                [SentryAdminController::class, 'exportAudit'],      $admin);
$r->post('/admin/sentry/audit/report',                [SentryAdminController::class, 'generateReport'],   $admin);

/*
|--------------------------------------------------------------------------
| Analytics — Dashboard & Reports
|--------------------------------------------------------------------------
*/
$r->get('/admin/analytics',                         [AdminAnalyticsController::class, 'dashboard'],        $admin);
$r->get('/admin/analytics/users',                   [AdminAnalyticsController::class, 'users'],            $admin);
$r->get('/admin/analytics/transactions',            [AdminAnalyticsController::class, 'transactions'],     $admin);
$r->get('/admin/analytics/social-tasks',            [AdminAnalyticsController::class, 'socialTasks'],      $admin);
$r->get('/admin/analytics/custom-tasks',            [AdminAnalyticsController::class, 'customTasks'],      $admin);
$r->get('/admin/analytics/ratings',                 [AdminAnalyticsController::class, 'ratings'],          $admin);
$r->get('/admin/analytics/revenue',                 [AdminAnalyticsController::class, 'revenue'],          $admin);
$r->get('/admin/analytics/system-health',           [AdminAnalyticsController::class, 'systemHealth'],     $admin);
$r->get('/admin/analytics/chart-data',              [AdminAnalyticsController::class, 'getChartData'],     $admin);
$r->post('/admin/analytics/export',                 [AdminAnalyticsController::class, 'exportReport'],     $admin);

/*
|--------------------------------------------------------------------------
| Social Tasks — Admin Panel
|--------------------------------------------------------------------------
*/
// آگهی‌ها
$r->get('/admin/social-tasks',                          [AdminSocialTaskController::class, 'index'],          $admin);
$r->get('/admin/social-tasks/stats',                    [AdminSocialTaskController::class, 'stats'],          $admin);
$r->get('/admin/social-tasks/{id}',                     [AdminSocialTaskController::class, 'show'],           $admin);
$r->post('/admin/social-tasks/{id}/approve',            [AdminSocialTaskController::class, 'approve'],        $admin);
$r->post('/admin/social-tasks/{id}/reject',             [AdminSocialTaskController::class, 'reject'],         $admin);
$r->post('/admin/social-tasks/{id}/pause',              [AdminSocialTaskController::class, 'pause'],          $admin);
$r->post('/admin/social-tasks/{id}/resume',             [AdminSocialTaskController::class, 'resume'],         $admin);
$r->post('/admin/social-tasks/{id}/cancel',             [AdminSocialTaskController::class, 'cancel'],         $admin);

// اجراها
$r->get('/admin/social-executions',                     [AdminSocialTaskController::class, 'executions'],     $admin);
$r->get('/admin/social-executions/{id}',                [AdminSocialTaskController::class, 'executionShow'],  $admin);
$r->post('/admin/social-executions/{id}/flag',          [AdminSocialTaskController::class, 'flagExecution'],  $admin);
$r->post('/admin/social-executions/{id}/override',      [AdminSocialTaskController::class, 'overrideDecision'],$admin);

// نظرات و امتیازات
$r->get('/admin/social-task-reviews',                   [AdminSocialTaskController::class, 'reviewRatings'],      $admin);
$r->get('/admin/social-task-reviews/{id}',              [AdminSocialTaskController::class, 'reviewRatingDetail'], $admin);
$r->post('/admin/social-task-reviews/{id}/moderate',    [AdminSocialTaskController::class, 'moderateRating'],     $admin);

// Trust
$r->get('/admin/social-trust',                          [AdminSocialTaskController::class, 'trustDashboard'], $admin);
$r->get('/admin/social-trust/user/{id}',                [AdminSocialTaskController::class, 'userTrust'],      $admin);
$r->post('/admin/social-trust/user/{id}/adjust',        [AdminSocialTaskController::class, 'adjustTrust'],    $admin);

// ── Fraud Detection ───────────────────────────────────────────────────────────
$r->get('/admin/fraud/risk-report',         [FraudController::class, 'getRiskReport'],     $admin);
$r->post('/admin/fraud/recalculate-score',  [FraudController::class, 'recalculateScore'],  $admin);
$r->post('/admin/fraud/execute-actions',    [FraudController::class, 'executeActions'],    $admin);
$r->get('/admin/fraud/high-risk-users',     [FraudController::class, 'getHighRiskUsers'],  $admin);
$r->post('/admin/fraud/clear-flags',        [FraudController::class, 'clearFlags'],        $admin);
$r->post('/admin/fraud/suspend-user',       [FraudController::class, 'suspendUser'],       $admin);
$r->post('/admin/fraud/unsuspend-user',     [FraudController::class, 'unsuspendUser'],     $admin);


// ── Message Moderation ────────────────────────────────────────────────────────
$r->get('/admin/messages/reports',              [MessageModerationController::class, 'reports'],       $admin);
$r->get('/admin/messages/reports/{id}',         [MessageModerationController::class, 'show'],          $admin);
$r->post('/admin/messages/reports/approve',     [MessageModerationController::class, 'approve'],       $admin);
$r->post('/admin/messages/reports/dismiss',     [MessageModerationController::class, 'dismiss'],       $admin);
$r->get('/admin/messages/blocked-users',        [MessageModerationController::class, 'blockedUsers'],  $admin);
$r->get('/admin/messages/stats',                [MessageModerationController::class, 'stats'],         $admin);

// End of Admin Routes
