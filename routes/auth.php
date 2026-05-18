<?php

/**
 * مسیرهای احراز هویت
 * - Guest: ثبت‌نام، ورود، فراموشی رمز
 * - Auth: خروج، تأیید ایمیل، ۲FA
 */

use App\Controllers\User\AuthController as UserAuthController;
use App\Controllers\User\TwoFactorController;
use App\Controllers\OAuthController;
use App\Middleware\GuestMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\CSRFMiddleware;
use App\Middleware\RateLimitMiddleware;

$router = app()->router;

// ── فقط برای مهمان (نمایش صفحات) ──────────────────────────────────────────
$router->group(['middleware' => [GuestMiddleware::class]], function ($router) {
    $router->get('/register',         [UserAuthController::class, 'showRegister']);
    $router->get('/login',            [UserAuthController::class, 'showLogin']);
    $router->get('/forgot-password',  [UserAuthController::class, 'showForgotPassword']);
    $router->get('/reset-password',   [UserAuthController::class, 'showResetPassword']);
});

// ── فقط برای مهمان (ارسال فرم‌ها - با CSRF) ──────────────────────────────
$router->group(['middleware' => [GuestMiddleware::class, CSRFMiddleware::class]], function ($router) {
    $router->post('/register',        [UserAuthController::class, 'register']);
    $router->post('/login',           [UserAuthController::class, 'login']);
    $router->post('/forgot-password', [UserAuthController::class, 'forgotPassword']);
    $router->post('/reset-password',  [UserAuthController::class, 'resetPassword']);
});

// ── تأیید دو مرحله‌ای (کاربر هنوز کاملاً لاگین نیست) ────────────────────
$router->get('/verify-2fa',  [TwoFactorController::class, 'showVerify'], [GuestMiddleware::class]);
$router->post('/verify-2fa', [TwoFactorController::class, 'verify'], [GuestMiddleware::class, CSRFMiddleware::class, RateLimitMiddleware::class]);

// ── تأیید ایمیل ──────────────────────────────────────────────────────────
$router->get('/email/verify',              [UserAuthController::class, 'verifyEmail']);
$router->get('/email/verify-code',         [UserAuthController::class, 'showVerifyEmail']);
$router->post('/email/verify-code',        [UserAuthController::class, 'verifyEmailByCode'], [CSRFMiddleware::class, RateLimitMiddleware::class]);
$router->post('/email/resend-verification',[UserAuthController::class, 'resendVerification'], [CSRFMiddleware::class, RateLimitMiddleware::class]);

// ── خروج (فقط POST — حذف GET برای جلوگیری از CSRF) ──────────────────────
$router->post('/logout', [UserAuthController::class, 'logout'], [AuthMiddleware::class, CSRFMiddleware::class]);

// ── Social Login — Google و Facebook (OAuth) ────────────────────────────
// برائے مہمان — OAuth redirect
app()->router->group(['middleware' => [GuestMiddleware::class]], function ($router) {
    $router->get('/login/google',    [OAuthController::class, 'loginGoogle']);
    $router->get('/login/facebook',  [OAuthController::class, 'loginFacebook']);
});

// OAuth callbacks (بغیر middleware — external providers سے) ─────────────────
$router->get('/auth/callback/google',   [OAuthController::class, 'callbackGoogle'], [GuestMiddleware::class, \App\Middleware\RateLimitMiddleware::class]);
$router->get('/auth/callback/facebook', [OAuthController::class, 'callbackFacebook'], [GuestMiddleware::class, \App\Middleware\RateLimitMiddleware::class]);
$router->get('/auth/oauth-confirm',     [OAuthController::class, 'showConfirmPassword'], [GuestMiddleware::class]);
$router->post('/auth/oauth-confirm',    [OAuthController::class, 'confirmPassword'], [GuestMiddleware::class, CSRFMiddleware::class, RateLimitMiddleware::class]);

// ── Social Accounts Management (authenticated users only) ────────────────
app()->router->group(['middleware' => [AuthMiddleware::class]], function ($router) {
    $router->get('/accounts/social',          [OAuthController::class, 'listAccounts']);
    $router->post('/accounts/social/link',    [OAuthController::class, 'linkAccount'], [CSRFMiddleware::class]);
    $router->post('/accounts/social/unlink',  [OAuthController::class, 'unlinkAccount'], [CSRFMiddleware::class]);
});
