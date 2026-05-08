<?php

/**
 * مسیرهای گمشده — اضافه‌شده پس از تقسیم‌بندی routes
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// ── User Controllers ──────────────────────────────────────────────────────
use App\Controllers\User\PredictionController;
use App\Controllers\User\AdtubeController;
use App\Controllers\User\InfluencerController;
use App\Controllers\User\OnlineStoreController;
use App\Controllers\User\VitrineController;
use App\Controllers\User\SeoAdController;
use App\Controllers\User\UserBannerController;

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

$auth  = [AuthMiddleware::class];
$admin = [AuthMiddleware::class, AdminMiddleware::class];
$r     = app()->router;

// ════════════════════════════════════════════════════════════════════════════
// USER ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی ─────────────────────────────────────────────────────────────────
$r->get('/prediction',            [PredictionController::class, 'index'],    $auth);
$r->get('/prediction/my-bets',    [PredictionController::class, 'myBets'],   $auth);
$r->get('/prediction/{id}',       [PredictionController::class, 'show'],     $auth);
$r->post('/prediction/place-bet', [PredictionController::class, 'placeBet'], $auth);


// ── تبلیغات ویدیویی (AdtubeController) ──────────────────────────────────────
// انجام‌دهنده
$r->get('/adtube',                             [AdtubeController::class, 'index'],       $auth);
$r->get('/adtube/history',                     [AdtubeController::class, 'history'],     $auth);
$r->post('/adtube/start',                      [AdtubeController::class, 'start'],       $auth);
$r->get('/adtube/{id}/execute',                [AdtubeController::class, 'showExecute'], $auth);
$r->post('/adtube/{id}/submit',                [AdtubeController::class, 'submit'],      $auth);
// تبلیغ‌دهنده
$r->get('/adtube/advertise',                   [AdtubeController::class, 'advertise'],   $auth);
$r->get('/adtube/advertise/create',            [AdtubeController::class, 'create'],      $auth);
$r->post('/adtube/advertise/store',            [AdtubeController::class, 'store'],       $auth);
$r->get('/adtube/advertise/{id}',              [AdtubeController::class, 'showAd'],      $auth);
$r->post('/adtube/advertise/{id}/pause',       [AdtubeController::class, 'pause'],       $auth);
$r->post('/adtube/advertise/{id}/resume',      [AdtubeController::class, 'resume'],      $auth);

// ── اینفلوئنسر ───────────────────────────────────────────────────────────────
// پروفایل و سفارش‌های دریافتی (انجام‌دهنده)
$r->get('/influencer',                                [InfluencerController::class, 'myProfile'],       $auth);
$r->get('/influencer/register',                       [InfluencerController::class, 'register'],        $auth);
$r->post('/influencer/register',                      [InfluencerController::class, 'storeProfile'],    $auth);
$r->post('/influencer/verify',                        [InfluencerController::class, 'submitVerification'], $auth);
// سفارش‌های دریافتی اینفلوئنسر
$r->get('/influencer/orders',                         [InfluencerController::class, 'myOrders'],        $auth);
$r->post('/influencer/orders/{id}/respond',           [InfluencerController::class, 'respondOrder'],    $auth);
$r->post('/influencer/orders/{id}/proof',             [InfluencerController::class, 'submitProof'],     $auth);
$r->get('/influencer/orders/{id}/dispute',            [InfluencerController::class, 'disputePanel'],    $auth);
$r->post('/influencer/orders/{id}/dispute/message',   [InfluencerController::class, 'sendDisputeMsg'],  $auth);
$r->post('/influencer/orders/{id}/dispute/escalate',  [InfluencerController::class, 'escalateDispute'], $auth);
$r->post('/influencer/orders/{id}/dispute/resolve',   [InfluencerController::class, 'resolveDisputePeer'], $auth);
// تبلیغ‌دهنده
$r->get('/influencer/advertise',                      [InfluencerController::class, 'advertise'],       $auth);
$r->get('/influencer/advertise/create',               [InfluencerController::class, 'createOrder'],     $auth);
$r->post('/influencer/advertise/store',               [InfluencerController::class, 'storeOrder'],      $auth);
$r->get('/influencer/advertise/my-orders',            [InfluencerController::class, 'myPlacedOrders'],  $auth);
$r->post('/influencer/advertise/orders/{id}/confirm', [InfluencerController::class, 'buyerConfirm'],    $auth);
$r->post('/influencer/advertise/orders/{id}/dispute', [InfluencerController::class, 'buyerDispute'],    $auth);

// ── ویترین (جایگزین Online Store) ────────────────────────────────────────────
$r->get('/vitrine',                        [VitrineController::class, 'index'],          $auth);
$r->get('/vitrine/wanted',                 [VitrineController::class, 'wantedIndex'],    $auth);
$r->get('/vitrine/wanted/create',          [VitrineController::class, 'createWanted'],   $auth);
$r->get('/vitrine/sell/create',            [VitrineController::class, 'create'],         $auth);
$r->get('/vitrine/my-listings',            [VitrineController::class, 'myListings'],     $auth);
$r->get('/vitrine/my-purchases',           [VitrineController::class, 'myPurchases'],    $auth);
$r->get('/vitrine/my-requests',            [VitrineController::class, 'myRequests'],     $auth);
$r->post('/vitrine/store',                 [VitrineController::class, 'store'],          $auth);
$r->post('/vitrine/request/{rid}/accept',  [VitrineController::class, 'acceptRequest'],  $auth);
$r->post('/vitrine/request/{rid}/reject',  [VitrineController::class, 'rejectRequest'],  $auth);
$r->get('/vitrine/{id}',                   [VitrineController::class, 'show'],           $auth);
$r->post('/vitrine/{id}/buy',              [VitrineController::class, 'buy'],            $auth);
$r->post('/vitrine/{id}/request',          [VitrineController::class, 'sendRequest'],    $auth);
$r->post('/vitrine/{id}/confirm',          [VitrineController::class, 'confirmDelivery'],$auth);
$r->post('/vitrine/{id}/dispute',          [VitrineController::class, 'dispute'],        $auth);
$r->post('/vitrine/{id}/watch',            [VitrineController::class, 'watch'],          $auth);
// redirect قدیمی → vitrine (backward compat)
$r->get('/online-store',              [VitrineController::class, 'index'],       $auth);
$r->get('/online-store/sell',         [VitrineController::class, 'myListings'],  $auth);
$r->get('/online-store/my-purchases', [VitrineController::class, 'myPurchases'], $auth);

// ── تبلیغ سئو (کاربر) ────────────────────────────────────────────────────────
$r->get('/seo-ad',               [SeoAdController::class, 'index'],  $auth);
$r->get('/seo-ad/create',        [SeoAdController::class, 'create'], $auth);
$r->post('/seo-ad/store',        [SeoAdController::class, 'store'],  $auth);
$r->get('/seo-ad/{id}',          [SeoAdController::class, 'show'],   $auth);
$r->post('/seo-ad/{id}/pause',   [SeoAdController::class, 'pause'],  $auth);
$r->post('/seo-ad/{id}/resume',  [SeoAdController::class, 'resume'], $auth);
$r->get('/seo-ad/{id}/export-csv',  [SeoAdController::class, 'exportCsv'], $auth);

// ── بنرهای سایزی کاربر (جایگاه‌های مختلف) ──────────────────────────────────
$r->get('/my-banners',               [UserBannerController::class, 'index'],  $auth);
$r->get('/my-banners/create',        [UserBannerController::class, 'create'], $auth);
$r->post('/my-banners/store',        [UserBannerController::class, 'store'],  $auth);
$r->get('/my-banners/{id}',          [UserBannerController::class, 'show'],   $auth);
$r->post('/my-banners/{id}/cancel',  [UserBannerController::class, 'cancel'], $auth);

// ════════════════════════════════════════════════════════════════════════════
// ADMIN ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی (ادمین) ─────────────────────────────────────────────────────────
$r->get('/admin/prediction',                     [AdminPredictionController::class, 'index'],       $admin);
$r->get('/admin/prediction/create',              [AdminPredictionController::class, 'create'],      $admin);
$r->post('/admin/prediction/store',              [AdminPredictionController::class, 'store'],       $admin);
$r->get('/admin/prediction/{id}',                [AdminPredictionController::class, 'show'],        $admin);
$r->post('/admin/prediction/{id}/settle',        [AdminPredictionController::class, 'settle'],      $admin);
$r->post('/admin/prediction/{id}/cancel',        [AdminPredictionController::class, 'cancel'],      $admin);
$r->post('/admin/prediction/{id}/close-betting', [AdminPredictionController::class, 'closeBetting'],$admin);

// ── فروشگاه آنلاین (ادمین) ───────────────────────────────────────────────────
// ── ادمین ویترین ─────────────────────────────────────────────────────────────
$r->get('/admin/vitrine',                    [AdminVitrineController::class, 'index'],       $admin);
$r->get('/admin/vitrine/settings',           [AdminVitrineController::class, 'settings'],    $admin);
$r->post('/admin/vitrine/settings/save',     [AdminVitrineController::class, 'saveSettings'],$admin);
$r->post('/admin/vitrine/{id}/approve',      [AdminVitrineController::class, 'approve'],     $admin);
$r->post('/admin/vitrine/{id}/reject',       [AdminVitrineController::class, 'reject'],      $admin);
$r->get('/admin/vitrine/{id}/dispute',       [AdminVitrineController::class, 'showDispute'], $admin);
$r->post('/admin/vitrine/{id}/resolve',      [AdminVitrineController::class, 'resolve'],     $admin);
$r->post('/admin/vitrine/{id}/release',      [AdminVitrineController::class, 'releaseFunds'],$admin);
$r->post('/admin/vitrine/{id}/refund',       [AdminVitrineController::class, 'refund'],      $admin);
// redirect قدیمی → vitrine
$r->get('/admin/online-store',               [AdminVitrineController::class, 'index'],       $admin);

// ── تبلیغ سئو (ادمین) ────────────────────────────────────────────────────────
$r->get('/admin/seo-ad',                   [AdminSeoAdController::class, 'index'],   $admin);
$r->post('/admin/seo-ad/{id}/approve',     [AdminSeoAdController::class, 'approve'], $admin);
$r->post('/admin/seo-ad/{id}/reject',      [AdminSeoAdController::class, 'reject'],  $admin);
$r->post('/admin/seo-ad/{id}/pause',       [AdminSeoAdController::class, 'pause'],   $admin);

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
$r->get('/manual-deposits',         [ManualDepositController::class, 'index'],  $auth);

// واریز کریپتو — shortcut
$r->get('/crypto-deposit/create',   [CryptoDepositController::class, 'create'], $auth);
$r->get('/crypto-deposits',         [CryptoDepositController::class, 'index'],  $auth);

// برداشت — shortcut
$r->get('/withdrawal/create',       [WithdrawalController::class, 'create'],     $auth);

// ════════════════════════════════════════════════════════════════════════════
// BANK CARDS — مسیرهای POST بدون {id} در URL (id از body می‌آید)
// ════════════════════════════════════════════════════════════════════════════


$r->post('/bank-cards/delete',      [UserBankCardController::class, 'delete'],     $auth);
$r->post('/bank-cards/set-default', [UserBankCardController::class, 'setDefault'], $auth);

// ════════════════════════════════════════════════════════════════════════════
// DASHBOARD SHORTCUTS — لینک‌های مستقیم داشبورد کاربر
// ════════════════════════════════════════════════════════════════════════════

// vote لاتاری از داشبورد (fetch مستقیم)
$r->post('/user/lottery/vote',   [UserLotteryController::class, 'vote'],  $auth);
