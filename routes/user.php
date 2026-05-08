<?php

/**
 * مسیرهای پنل کاربری — همه نیاز به AuthMiddleware دارند
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdvancedFraudMiddleware;
use App\Middleware\CSRFMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Controllers\User\DashboardController    as UserDashboardController;
use App\Controllers\User\ProfileController;
use App\Controllers\User\SettingsController;
use App\Controllers\User\SessionController      as UserSessionController;
use App\Controllers\User\KYCController          as UserKYCController;
use App\Controllers\User\BankCardController     as UserBankCardController;
use App\Controllers\User\NotificationController as UserNotificationController;
use App\Controllers\User\TwoFactorController;
use App\Controllers\User\SocialAccountController;
use App\Controllers\User\SeoController;
use App\Controllers\User\CustomTaskController;
use App\Controllers\User\ContentController;
use App\Controllers\User\InvestmentController       as UserInvestmentController;
use App\Controllers\User\LotteryController          as UserLotteryController;
use App\Controllers\User\ReferralController         as UserReferralController;
use App\Controllers\User\LevelController            as UserLevelController;
use App\Controllers\User\BugReportController        as UserBugReportController;
use App\Controllers\User\BannerRequestController    as UserBannerRequestController;
use App\Controllers\User\AdvertiserController;
use App\Controllers\User\ApiTokenController;
use App\Controllers\User\CouponController;
use App\Controllers\SearchController;
use App\Controllers\User\CustomTaskAdController;
use App\Controllers\User\SocialTaskController;
use App\Controllers\Api\SocialTaskApiController;
use App\Controllers\User\AppealController;
use App\Controllers\User\MessageController;

$auth     = [AuthMiddleware::class];
$authCSRF = [AuthMiddleware::class, CSRFMiddleware::class];
$authF    = [AuthMiddleware::class, AdvancedFraudMiddleware::class];
$secure   = [AuthMiddleware::class, CSRFMiddleware::class, AdvancedFraudMiddleware::class, RateLimitMiddleware::class];
$r        = app()->router;

// ── داشبورد ──────────────────────────────────────────────────────────────
$r->get('/dashboard', [UserDashboardController::class, 'index'], $authF);

// ── پروفایل ──────────────────────────────────────────────────────────────
$r->get('/profile',                  [ProfileController::class, 'index'],          $auth);
$r->post('/profile/update',          [ProfileController::class, 'update'],         $authCSRF);
$r->post('/profile/change-password', [ProfileController::class, 'changePassword'], $authCSRF);
$r->post('/profile/upload-avatar',   [ProfileController::class, 'uploadAvatar'],   $authCSRF);
$r->post('/profile/delete-avatar',   [ProfileController::class, 'deleteAvatar'],   $authCSRF);

// ── احراز هویت دو مرحله‌ای ───────────────────────────────────────────────
$r->get('/two-factor',         [TwoFactorController::class, 'index'],   $auth);
$r->post('/two-factor/enable', [TwoFactorController::class, 'enable'],  $authCSRF);
$r->post('/two-factor/disable',[TwoFactorController::class, 'disable'], $authCSRF);

// ── جلسات فعال ───────────────────────────────────────────────────────────
$r->get('/sessions',                      [UserSessionController::class, 'index'],     $auth);
$r->post('/sessions/terminate/{id}',      [UserSessionController::class, 'terminate'], $authCSRF);

// ── KYC ──────────────────────────────────────────────────────────────────
$r->get('/kyc',           [UserKYCController::class, 'index'],  $auth);
$r->get('/kyc/upload',    [UserKYCController::class, 'upload'], $auth);
$r->post('/kyc/submit',   [UserKYCController::class, 'submit'], $authCSRF);
$r->get('/kyc/status',    [UserKYCController::class, 'status'], $auth);

// ── کارت‌های بانکی ────────────────────────────────────────────────────────
$r->get('/bank-cards',                    [UserBankCardController::class, 'index'],      $auth);
$r->get('/bank-cards/create',             [UserBankCardController::class, 'create'],     $auth);
$r->post('/bank-cards/store',             [UserBankCardController::class, 'store'],      $authCSRF);
$r->post('/bank-cards/delete/{id}',       [UserBankCardController::class, 'delete'],     $authCSRF);
$r->post('/bank-cards/set-default/{id}',  [UserBankCardController::class, 'setDefault'], $authCSRF);

// ── اعلان‌ها ──────────────────────────────────────────────────────────────
$r->get('/notifications',                          [UserNotificationController::class, 'index'],             $auth);
$r->get('/notifications/get',                      [UserNotificationController::class, 'get'],               $auth);
$r->get('/notifications/unread-count',             [UserNotificationController::class, 'unreadCount'],       $auth);
$r->get('/notifications/preferences',              [UserNotificationController::class, 'preferences'],       $auth);
$r->post('/notifications/mark-read',               [UserNotificationController::class, 'markAsRead'],        $auth);
$r->post('/notifications/mark-all-read',           [UserNotificationController::class, 'markAllAsRead'],     $auth);
$r->post('/notifications/archive',                 [UserNotificationController::class, 'archive'],           $auth);
$r->post('/notifications/preferences/update',      [UserNotificationController::class, 'updatePreferences'], $auth);
$r->get('/notifications/poll',                     [UserNotificationController::class, 'poll'],             $auth);
$r->get('/notifications/click',                    [UserNotificationController::class, 'click'],            $auth);
$r->post('/notifications/delete',                  [UserNotificationController::class, 'delete'],           $auth);
$r->post('/notifications/fcm-token',               [UserNotificationController::class, 'saveFcmToken'],     $auth);

// ── تنظیمات کاربر ──────────────────────────────────────────────────────────
$r->get('/settings/general',                       [SettingsController::class, 'general'],                   $auth);
$r->post('/settings/general/update',               [SettingsController::class, 'updateGeneral'],             $auth);
$r->get('/settings/privacy',                       [SettingsController::class, 'privacy'],                   $auth);
$r->post('/settings/privacy/update',               [SettingsController::class, 'updatePrivacy'],             $auth);
$r->get('/settings/security',                      [SettingsController::class, 'security'],                  $auth);
$r->post('/settings/security/update',              [SettingsController::class, 'updateSecurity'],            $auth);
$r->get('/settings/notifications',                 [SettingsController::class, 'notifications'],             $auth);
$r->post('/settings/notifications/update',         [SettingsController::class, 'updateNotifications'],       $auth);
$r->get('/settings/data-export',                   [SettingsController::class, 'dataExport'],                $auth);
$r->get('/settings/account-deletion',              [SettingsController::class, 'accountDeletion'],           $auth);
$r->post('/settings/account-deletion/request',     [SettingsController::class, 'requestAccountDeletion'],    $auth);
$r->post('/settings/account-deletion/cancel',      [SettingsController::class, 'cancelAccountDeletion'],     $auth);

// ── حساب‌های اجتماعی ──────────────────────────────────────────────────────
$r->get('/social-accounts',              [SocialAccountController::class, 'index'],      $auth);
$r->get('/social-accounts/create',       [SocialAccountController::class, 'showCreate'], $auth);
$r->post('/social-accounts/store',       [SocialAccountController::class, 'store'],      $auth);
$r->get('/social-accounts/{id}/edit',    [SocialAccountController::class, 'showEdit'],   $auth);
$r->post('/social-accounts/{id}/update', [SocialAccountController::class, 'update'],     $auth);
$r->post('/social-accounts/{id}/delete', [SocialAccountController::class, 'delete'],     $auth);

// ============================================
// USER - Worker (انجام‌دهنده)
// ============================================

$r->get('/seo',                [SeoController::class, 'index'],       $auth);
$r->get('/seo/history',        [SeoController::class, 'history'],     $auth);
$r->post('/seo/start',         [SeoController::class, 'start'],       $auth);
$r->get('/seo/{id}/execute',   [SeoController::class, 'execute'], $auth);
$r->post('/seo/{id}/complete', [SeoController::class, 'complete'],    $auth);
$r->get('/seo/execution/{id}',   [SeoController::class, 'showExecution'], $auth);
$r->post('/seo/{id}/cancel', [SeoController::class, 'cancel'],    $auth);

// لیست وظایف تبلیغ‌دهنده (My Ads)
$r->get('/custom-tasks', [CustomTaskController::class, 'index'], $auth);

// لیست وظایف موجود برای انجام (Worker)
$r->get('/custom-tasks/available', [CustomTaskController::class, 'available'], $auth);

// تاریخچه انجام‌های من
$r->get('/custom-tasks/my-submissions', [CustomTaskController::class, 'mySubmissions'], $auth);

// ایجاد وظیفه جدید
$r->get('/custom-tasks/create', [CustomTaskController::class, 'create'], $auth);
$r->post('/custom-tasks/store', [CustomTaskController::class, 'store'], $auth);

// جزئیات وظیفه و submission ها
$r->get('/custom-tasks/{id}', [CustomTaskController::class, 'show'], $auth);

// شروع انجام تسک (Ajax)
$r->post('/custom-tasks/start', [CustomTaskController::class, 'start'], $auth);

// ارسال مدرک (Ajax)
$r->post('/custom-tasks/{id}/submit-proof', [CustomTaskController::class, 'submitProof'], $auth);

// تایید/رد توسط تبلیغ‌دهنده (Ajax)
$r->post('/custom-tasks/review', [CustomTaskController::class, 'review'], $auth);

// ── تبلیغات استوری ────────────────────────────────────────────────────────
// InfluencerController routes are in routes/missing.php

// ── اعتراضات کاربر ─────────────────────────────────────────────────────────────
$r->get('/appeals',                    [AppealController::class, 'index'],                 $auth);
$r->get('/appeals/create',             [AppealController::class, 'showSubmitForm'],        $auth);
$r->post('/appeals/store',             [AppealController::class, 'submit'],                $auth);
$r->get('/appeals/{id}',               [AppealController::class, 'show'],                  $auth);
$r->get('/appeals/templates',          [AppealController::class, 'getTemplate'],           $auth);
$r->get('/appeals/status',             [AppealController::class, 'checkSubmissionStatus'], $auth);

// ── پیام‌های مستقیم ──────────────────────────────────────────────────────────
$r->get('/messages',                        [MessageController::class, 'index'],          $auth);
$r->get('/messages/{id}',                   [MessageController::class, 'show'],           $auth);
$r->post('/messages/send',                  [MessageController::class, 'send'],           $auth);
$r->post('/messages/{id}/delete',           [MessageController::class, 'delete'],         $auth);
$r->post('/messages/typing',                [MessageController::class, 'setTyping'],      $auth);
$r->get('/messages/typing/users',           [MessageController::class, 'getTypingUsers'], $auth);
$r->post('/messages/{id}/reaction',         [MessageController::class, 'addReaction'],    $auth);
$r->get('/messages/unread/count',           [MessageController::class, 'getUnreadCount'], $auth);

// ── محتوا ─────────────────────────────────────────────────────────────────
$r->get('/content',           [ContentController::class, 'index'],    $auth);
$r->get('/content/create',    [ContentController::class, 'create'],   $auth);
$r->post('/content/store',    [ContentController::class, 'store'],    $auth);
$r->get('/content/revenues',  [ContentController::class, 'revenues'], $auth);
$r->get('/content/{id}',      [ContentController::class, 'show'],     $auth);

// ── سرمایه‌گذاری ──────────────────────────────────────────────────────────
$r->get('/investment',                 [UserInvestmentController::class, 'index'],         $auth);
$r->get('/investment/create',          [UserInvestmentController::class, 'create'],        $auth);
$r->post('/investment/store',          [UserInvestmentController::class, 'store'],         $secure);
$r->post('/investment/withdraw',       [UserInvestmentController::class, 'withdraw'],      $secure);
$r->get('/investment/profit-history',  [UserInvestmentController::class, 'profitHistory'], $auth);

// ── قرعه‌کشی ──────────────────────────────────────────────────────────────
$r->get('/lottery',       [UserLotteryController::class, 'index'], $auth);
$r->post('/lottery/join', [UserLotteryController::class, 'join'],  $auth);
$r->post('/lottery/vote', [UserLotteryController::class, 'vote'],  $auth);

// ── زیرمجموعه‌گیری ────────────────────────────────────────────────────────
$r->get('/referral',                [UserReferralController::class, 'index'],        $auth);
$r->get('/referral/commissions',    [UserReferralController::class, 'commissions'],  $auth);
$r->get('/referral/referred-users', [UserReferralController::class, 'referredUsers'],$auth);

// ── سطح‌بندی ──────────────────────────────────────────────────────────────
$r->get('/level',           [UserLevelController::class, 'index'],    $auth);
$r->post('/level/purchase', [UserLevelController::class, 'purchase'], $secure);

// ── گزارش باگ ─────────────────────────────────────────────────────────────
$r->get('/bug-reports',                   [UserBugReportController::class, 'index'],      $auth);
$r->post('/bug-reports/store',            [UserBugReportController::class, 'store'],      $auth);
$r->get('/bug-reports/{id}',              [UserBugReportController::class, 'show'],       $auth);
$r->post('/bug-reports/{id}/comment',     [UserBugReportController::class, 'addComment'], $auth);

// ── توکن‌های API کاربر ────────────────────────────────────────────────────
$r->get('/api-tokens',              [ApiTokenController::class, 'index'],  $auth);
$r->post('/api-tokens/create',      [ApiTokenController::class, 'create'], $auth);
$r->post('/api-tokens/{id}/revoke', [ApiTokenController::class, 'revoke'], $auth);

// ── کوپن ─────────────────────────────────────────────────────────────────
$r->post('/coupons/validate', [CouponController::class, 'validate'], $auth);
$r->get('/coupons/history',   [CouponController::class, 'history'],  $auth);

/*
|--------------------------------------------------------------------------
| Social Tasks — Executor
|--------------------------------------------------------------------------
*/
$r->get('/social-tasks',                [SocialTaskController::class, 'index'],              $auth);
$r->get('/social-tasks/dashboard',      [SocialTaskController::class, 'executorDashboard'],  $auth);
$r->get('/social-tasks/history',        [SocialTaskController::class, 'history'],            $auth);
$r->post('/social-tasks/start',         [SocialTaskController::class, 'start'],              $auth);
$r->get('/social-tasks/{id}/execute',   [SocialTaskController::class, 'showExecute'],        $auth);
$r->post('/social-tasks/{id}/submit',   [SocialTaskController::class, 'submit'],             $auth);
$r->get('/social-tasks/{id}/rate',        [SocialTaskController::class, 'rateExecutionForm'],  $auth);
$r->post('/social-tasks/{id}/rate',       [SocialTaskController::class, 'rateExecution'],      $auth);
$r->get('/social-ratings/history',        [SocialTaskController::class, 'ratingHistory'],     $auth);

/*
|--------------------------------------------------------------------------
| Social Ads — Advertiser
|--------------------------------------------------------------------------
*/
$r->get('/social-ads',                          [SocialTaskController::class, 'myAds'],              $auth);
$r->get('/social-ads/dashboard',                [SocialTaskController::class, 'advertiserDashboard'], $auth);
$r->get('/social-ads/create',                   [SocialTaskController::class, 'create'],             $auth);
$r->post('/social-ads/store',                   [SocialTaskController::class, 'store'],              $auth);
$r->get('/social-ads/{id}',                     [SocialTaskController::class, 'show'],               $auth);
$r->post('/social-ads/{id}/pause',              [SocialTaskController::class, 'pause'],              $auth);
$r->post('/social-ads/{id}/resume',             [SocialTaskController::class, 'resume'],             $auth);
$r->post('/social-ads/{id}/cancel',             [SocialTaskController::class, 'cancel'],             $auth);
$r->get('/social-ads/execution/{id}',           [SocialTaskController::class, 'executionDetail'],    $auth);
$r->post('/social-ads/execution/{id}/approve',  [SocialTaskController::class, 'approveExecution'],   $auth);
$r->post('/social-ads/execution/{id}/reject',   [SocialTaskController::class, 'rejectExecution'],    $auth);

/*
|--------------------------------------------------------------------------
| Social Tasks — API (موبایل)
|--------------------------------------------------------------------------
*/
$r->post('/api/social-tasks/behavior',        [SocialTaskApiController::class, 'recordBehavior'], $auth);
$r->post('/api/social-tasks/camera-verify',   [SocialTaskApiController::class, 'cameraVerify'],   $auth);
$r->get('/api/social-tasks/trust-status',     [SocialTaskApiController::class, 'trustStatus'],    $auth);

// ── جستجو ─────────────────────────────────────────────────────────────────
$r->get('/search',      [SearchController::class, 'fullResults'], $auth);
$r->get('/search/ajax', [SearchController::class, 'userSearch'],  $auth);

// لیست درخواست‌های بنر کاربر
$r->get('/banner-request',    [BannerRequestController::class, 'index'], $auth);
$r->get('/banner-request/create', [BannerRequestController::class, 'create'], $auth);
$r->post('/banner-request/store',  [BannerRequestController::class, 'store'], $auth);
$r->get('/banner-request/{id}',    [BannerRequestController::class, 'show'], $auth);

/*
|--------------------------------------------------------------------------
| Custom Tasks - Advertiser
|--------------------------------------------------------------------------
*/
$r->get('/custom-tasks/ad', [CustomTaskAdController::class, 'index'], $auth);
$r->get('/custom-tasks/ad/create', [CustomTaskAdController::class, 'create'], $auth);
$r->post('/custom-tasks/ad', [CustomTaskAdController::class, 'store'], $authCSRF);
$r->get('/custom-tasks/ad/{id}', [CustomTaskAdController::class, 'show'], $auth);

$r->post('/custom-tasks/ad/{id}/publish', [CustomTaskAdController::class, 'publish'], $authCSRF);
$r->post('/custom-tasks/ad/{id}/pause', [CustomTaskAdController::class, 'pause'], $authCSRF);
$r->post('/custom-tasks/ad/{id}/cancel', [CustomTaskAdController::class, 'cancel'], $authCSRF);

$r->post('/custom-tasks/ad/submissions/{id}/approve', [CustomTaskAdController::class, 'approveSubmission'], $authCSRF);
$r->post('/custom-tasks/ad/submissions/{id}/reject', [CustomTaskAdController::class, 'rejectSubmission'], $authCSRF);

/*
|--------------------------------------------------------------------------
| Custom Tasks - Executor
|--------------------------------------------------------------------------
*/
// Note: Available routes are already defined above, but we ensure consistency here
$r->get('/custom-tasks/available-list', [CustomTaskController::class, 'available'], $auth);
$r->get('/custom-tasks/detail/{id}', [CustomTaskController::class, 'show'], $auth);
$r->post('/custom-tasks/{id}/start-execution', [CustomTaskController::class, 'start'], $authCSRF);
$r->post('/custom-tasks/submissions/{id}/submit-proof-action', [CustomTaskController::class, 'submitProof'], $authCSRF);

$r->get('/custom-tasks/my-submissions-list', [CustomTaskController::class, 'mySubmissions'], $auth);
$r->get('/custom-tasks/disputes-list', [CustomTaskController::class, 'disputes'], $auth);
$r->post('/custom-tasks/submissions/{id}/dispute-action', [CustomTaskController::class, 'storeDispute'], $authCSRF);