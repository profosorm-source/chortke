<?php

/**
 * مسیرهای گمشده — اضافه‌شده پس از تقسیم‌بندی routes
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Middleware\CSRFMiddleware;

// ── User Controllers ──────────────────────────────────────────────────────
use App\Controllers\User\PredictionController;
use App\Controllers\User\AdtubeController;
use App\Controllers\User\InfluencerController;
use App\Controllers\User\OnlineStoreController;
use App\Controllers\User\VitrineController;
use App\Controllers\User\SeoAdController;
// UserBannerController definition removed

use App\Controllers\User\ManualDepositController;
use App\Controllers\User\CryptoDepositController;
use App\Controllers\User\WithdrawalController;
use App\Controllers\User\BankCardController as UserBankCardController;
use App\Controllers\User\AdTaskController   as UserAdTaskController;
use App\Controllers\User\LotteryController  as UserLotteryController;

// ── Admin Controllers ─────────────────────────────────────────────────────
use App\Controllers\Admin\PredictionController   as AdminPredictionController;
use App\Controllers\Admin\OnlineStoreController  as AdminOnlineStoreController;
use App\Controllers\Admin\VitrineController      as AdminVitrineController;
use App\Controllers\Admin\SeoAdController        as AdminSeoAdController;
use App\Controllers\Admin\StartupBannerController as AdminStartupBannerController;
use App\Controllers\Admin\LogController          as AdminLogController;
use App\Controllers\Admin\FraudDashboardController;
use App\Controllers\Admin\SystemController       as AdminSystemController;

$auth      = [AuthMiddleware::class];
$authCSRF  = [AuthMiddleware::class, CSRFMiddleware::class];
$admin     = [AuthMiddleware::class, AdminMiddleware::class];
$adminCSRF = [AuthMiddleware::class, AdminMiddleware::class, CSRFMiddleware::class];

$vitrineAuth     = array_merge($auth, [\App\Middleware\RequireFeature::class . ':vitrine_enabled']);
$vitrineAuthCSRF = array_merge($authCSRF, [\App\Middleware\RequireFeature::class . ':vitrine_enabled']);

$r         = app()->router;

// ── Metrics & Health ──────────────────────────────────────────────────────
$r->get('/health', [\App\Controllers\MetricsController::class, 'health']);
$r->get('/metrics', [\App\Controllers\MetricsController::class, 'metrics']);

// ════════════════════════════════════════════════════════════════════════════
// USER ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی ─────────────────────────────────────────────────────────────────
$r->get('/prediction',            [PredictionController::class, 'index'],    $auth);
$r->get('/prediction/my-bets',    [PredictionController::class, 'myBets'],   $auth);
$r->get('/prediction/{id}',       [PredictionController::class, 'show'],     $auth);
$r->post('/prediction/place-bet', [PredictionController::class, 'placeBet'], $authCSRF);


// ── تبلیغات ویدیویی (AdtubeController) ──────────────────────────────────────
// انجام‌دهنده
$r->get('/adtube',                             [AdtubeController::class, 'index'],       $auth);
$r->get('/adtube/history',                     [AdtubeController::class, 'history'],     $auth);
$r->post('/adtube/start',                      [AdtubeController::class, 'start'],       $authCSRF);
$r->get('/adtube/{id}/execute',                [AdtubeController::class, 'showExecute'], $auth);
$r->post('/adtube/{id}/submit',                [AdtubeController::class, 'submit'],      $authCSRF);
// تبلیغ‌دهنده
$r->get('/adtube/ads',                   [AdtubeController::class, 'advertise'],   $auth);
$r->get('/adtube/ads/create',            [AdtubeController::class, 'create'],      $auth);
$r->post('/adtube/ads/store',            [AdtubeController::class, 'store'],       $authCSRF);
$r->get('/adtube/ads/{id}',              [AdtubeController::class, 'showAd'],      $auth);
$r->post('/adtube/ads/{id}/pause',       [AdtubeController::class, 'pause'],       $authCSRF);
$r->post('/adtube/ads/{id}/resume',      [AdtubeController::class, 'resume'],      $authCSRF);

// ── اینفلوئنسر ───────────────────────────────────────────────────────────────
// پروفایل و سفارش‌های دریافتی (انجام‌دهنده)
$r->get('/influencer',                                [InfluencerController::class, 'myProfile'],       $auth);
$r->get('/influencer/register',                       [InfluencerController::class, 'register'],        $auth);
$r->post('/influencer/register',                      [InfluencerController::class, 'storeProfile'],    $authCSRF);
$r->post('/influencer/verify',                        [InfluencerController::class, 'submitVerification'], $authCSRF);
// سفارش‌های دریافتی اینفلوئنسر
$r->get('/influencer/orders',                         [InfluencerController::class, 'myOrders'],        $auth);
$r->post('/influencer/orders/{id}/respond',           [InfluencerController::class, 'respondOrder'],    $authCSRF);
$r->post('/influencer/orders/{id}/proof',             [InfluencerController::class, 'submitProof'],     $authCSRF);
$r->get('/influencer/orders/{id}/dispute',            [InfluencerController::class, 'disputePanel'],    $auth);
$r->post('/influencer/orders/{id}/dispute/message',   [InfluencerController::class, 'sendDisputeMsg'],  $authCSRF);
$r->post('/influencer/orders/{id}/dispute/escalate',  [InfluencerController::class, 'escalateDispute'], $authCSRF);
$r->post('/influencer/orders/{id}/dispute/resolve',   [InfluencerController::class, 'resolveDisputePeer'], $authCSRF);
// تبلیغ‌دهنده
$r->get('/influencer/ads',                      [InfluencerController::class, 'advertise'],       $auth);
$r->get('/influencer/ads/create',               [InfluencerController::class, 'createOrder'],     $auth);
$r->post('/influencer/ads/store',               [InfluencerController::class, 'storeOrder'],      $authCSRF);
$r->get('/influencer/ads/my-orders',            [InfluencerController::class, 'myPlacedOrders'],  $auth);
$r->post('/influencer/ads/orders/{id}/confirm', [InfluencerController::class, 'buyerConfirm'],    $authCSRF);
$r->post('/influencer/ads/orders/{id}/dispute', [InfluencerController::class, 'buyerDispute'],    $authCSRF);

// ── ویترین (جایگزین Online Store) ────────────────────────────────────────────
$r->get('/vitrine',                        [VitrineController::class, 'index'],          $vitrineAuth);
$r->get('/vitrine/wanted',                 [VitrineController::class, 'wantedIndex'],    $vitrineAuth);
$r->get('/vitrine/wanted/create',          [VitrineController::class, 'createWanted'],   $vitrineAuth);
$r->get('/vitrine/sell/create',            [VitrineController::class, 'create'],         $vitrineAuth);
$r->get('/vitrine/my-listings',            [VitrineController::class, 'myListings'],     $vitrineAuth);
$r->get('/vitrine/my-purchases',           [VitrineController::class, 'myPurchases'],    $vitrineAuth);
$r->get('/vitrine/my-requests',            [VitrineController::class, 'myRequests'],     $vitrineAuth);
$r->post('/vitrine/store',                 [VitrineController::class, 'store'],          $vitrineAuthCSRF);
$r->post('/vitrine/request/{rid}/accept',  [VitrineController::class, 'acceptRequest'],  $vitrineAuthCSRF);
$r->post('/vitrine/request/{rid}/reject',  [VitrineController::class, 'rejectRequest'],  $vitrineAuthCSRF);
$r->get('/vitrine/{id}',                   [VitrineController::class, 'show'],           $vitrineAuth);
$r->post('/vitrine/{id}/buy',              [VitrineController::class, 'buy'],            $vitrineAuthCSRF);
$r->post('/vitrine/{id}/request',          [VitrineController::class, 'sendRequest'],    $vitrineAuthCSRF);
$r->post('/vitrine/{id}/confirm',          [VitrineController::class, 'confirmDelivery'],$vitrineAuthCSRF);
$r->post('/vitrine/{id}/dispute',          [VitrineController::class, 'dispute'],        $vitrineAuthCSRF);
$r->post('/vitrine/{id}/watch',            [VitrineController::class, 'watch'],          $vitrineAuthCSRF);
// redirect قدیمی → vitrine (backward compat)
$r->get('/online-store',              [VitrineController::class, 'index'],       $vitrineAuth);
$r->get('/online-store/sell',         [VitrineController::class, 'myListings'],  $vitrineAuth);
$r->get('/online-store/my-purchases', [VitrineController::class, 'myPurchases'], $vitrineAuth);

// ── تبلیغ سئو (کاربر) ────────────────────────────────────────────────────────
$r->get('/seo-ad',               [SeoAdController::class, 'index'],  $auth);
$r->get('/seo-ad/create',        [SeoAdController::class, 'create'], $auth);
$r->post('/seo-ad/store',        [SeoAdController::class, 'store'],  $authCSRF);
$r->get('/seo-ad/{id}',          [SeoAdController::class, 'show'],   $auth);
$r->post('/seo-ad/{id}/pause',   [SeoAdController::class, 'pause'],  $authCSRF);
$r->post('/seo-ad/{id}/resume',  [SeoAdController::class, 'resume'], $authCSRF);
$r->get('/seo-ad/{id}/export-csv',  [SeoAdController::class, 'exportCsv'], $auth);

// Banners now routed via banner-request in routes/user.php

// ════════════════════════════════════════════════════════════════════════════
// ADMIN ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی (ادمین) ─────────────────────────────────────────────────────────
$r->get('/admin/prediction',                     [AdminPredictionController::class, 'index'],       $admin);
$r->get('/admin/prediction/create',              [AdminPredictionController::class, 'create'],      $admin);
$r->post('/admin/prediction/store',              [AdminPredictionController::class, 'store'],       $adminCSRF);
$r->get('/admin/prediction/{id}',                [AdminPredictionController::class, 'show'],        $admin);
$r->post('/admin/prediction/{id}/settle',        [AdminPredictionController::class, 'settle'],      $adminCSRF);
$r->post('/admin/prediction/{id}/update',        [AdminPredictionController::class, 'update'],      $adminCSRF);
$r->post('/admin/prediction/{id}/cancel',        [AdminPredictionController::class, 'cancel'],      $adminCSRF);
$r->post('/admin/prediction/{id}/close-betting', [AdminPredictionController::class, 'closeBetting'],$adminCSRF);

// ── فروشگاه آنلاین (ادمین) ───────────────────────────────────────────────────
// ── ادمین ویترین ─────────────────────────────────────────────────────────────
$r->get('/admin/vitrine',                    [AdminVitrineController::class, 'index'],       $admin);
$r->get('/admin/vitrine/settings',           [AdminVitrineController::class, 'settings'],    $admin);
$r->post('/admin/vitrine/settings/save',     [AdminVitrineController::class, 'saveSettings'],$adminCSRF);
$r->post('/admin/vitrine/{id}/approve',      [AdminVitrineController::class, 'approve'],     $adminCSRF);
$r->post('/admin/vitrine/{id}/reject',       [AdminVitrineController::class, 'reject'],      $adminCSRF);
$r->get('/admin/vitrine/{id}/dispute',       [AdminVitrineController::class, 'showDispute'], $admin);
$r->post('/admin/vitrine/{id}/resolve',      [AdminVitrineController::class, 'resolve'],     $adminCSRF);
$r->post('/admin/vitrine/{id}/release',      [AdminVitrineController::class, 'releaseFunds'],$adminCSRF);
$r->post('/admin/vitrine/{id}/refund',       [AdminVitrineController::class, 'refund'],      $adminCSRF);
// redirect قدیمی → vitrine
$r->get('/admin/online-store',               [AdminVitrineController::class, 'index'],       $admin);

// ── تبلیغ سئو (ادمین) ────────────────────────────────────────────────────────
$r->get('/admin/seo-ad',                   [AdminSeoAdController::class, 'index'],   $admin);
$r->post('/admin/seo-ad/{id}/approve',     [AdminSeoAdController::class, 'approve'], $adminCSRF);
$r->post('/admin/seo-ad/{id}/reject',      [AdminSeoAdController::class, 'reject'],  $adminCSRF);
$r->post('/admin/seo-ad/{id}/pause',       [AdminSeoAdController::class, 'pause'],   $adminCSRF);

// ── لاگ فعالیت‌ها (route گمشده: activityLogs) ────────────────────────────────
$r->get('/admin/logs/activity', [AdminLogController::class, 'activityLogs'], $admin);

// ── fraud — redirect مستقیم /admin/fraud به داشبورد fraud ───────────────────
$r->get('/admin/fraud', [FraudDashboardController::class, 'index'], $admin);

// ── کپچا (تنظیمات) ────────────────────────────────────────────────────────────
// /admin/captcha/settings از طریق SystemSettingController سرو می‌شود
// چون فرم آن به /admin/settings/update پست می‌کند (بررسی view تأیید کرد)
// ریدایرکت ساده به صفحه تنظیمات:
$r->get('/admin/captcha/settings', function() {
    app()->response->redirect(url('/admin/settings?section=captcha'));
}, $admin);


// ════════════════════════════════════════════════════════════════════════════
// WALLET SHORTCUTS — مسیرهای کوتاه که view ها مستقیم استفاده می‌کنند
// ════════════════════════════════════════════════════════════════════════════


// واریز دستی — shortcut
$r->get('/manual-deposit/create',   [ManualDepositController::class, 'create'], $auth);

// واریز کریپتو — shortcut
$r->get('/crypto-deposit/create',   [CryptoDepositController::class, 'create'], $auth);

// برداشت — shortcut
$r->get('/withdrawal/create',       [WithdrawalController::class, 'create'],     $auth);
$r->post('/withdrawal/challenge/request', [WithdrawalController::class, 'requestWithdrawalChallenge'], $authCSRF);
$r->post('/withdrawal/challenge/verify',  [WithdrawalController::class, 'verifyWithdrawalChallenge'],  $authCSRF);

// ════════════════════════════════════════════════════════════════════════════
// BANK CARDS — مسیرهای GET برای ایجاد/نمایش در user.php موجودند
// ════════════════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════════════════
// DASHBOARD SHORTCUTS — لینک‌های مستقیم داشبورد کاربر
// ════════════════════════════════════════════════════════════════════════════

// vote لاتاری از داشبورد (fetch مستقیم)
$r->post('/user/lottery/vote',   [UserLotteryController::class, 'vote'],  $authCSRF);
