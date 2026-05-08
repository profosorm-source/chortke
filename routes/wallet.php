<?php

/**
 * مسیرهای کیف پول، واریز، برداشت و پرداخت
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\CSRFMiddleware;
use App\Middleware\AdvancedFraudMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Controllers\User\WalletController       as UserWalletController;
use App\Controllers\User\ManualDepositController;
use App\Controllers\User\CryptoDepositController;
use App\Controllers\User\WithdrawalController;
use App\Controllers\PaymentController;

$auth = [AuthMiddleware::class];
$secureAuth = [AuthMiddleware::class, CSRFMiddleware::class, AdvancedFraudMiddleware::class, RateLimitMiddleware::class];
$r    = app()->router;

// ── کیف پول ──────────────────────────────────────────────────────────────
$r->get('/wallet',         [UserWalletController::class, 'index'],        $auth);
$r->get('/wallet/deposit', [UserWalletController::class, 'depositIndex'], $auth);
$r->get('/wallet/history', [UserWalletController::class, 'history'],      $auth);

// ── واریز دستی (IRT) ──────────────────────────────────────────────────────
$r->get('/wallet/deposit/manual',  [ManualDepositController::class, 'create'], $auth);
$r->post('/wallet/deposit/manual', [ManualDepositController::class, 'store'],  $secureAuth);
$r->get('/manual-deposits',        [ManualDepositController::class, 'index'],  $auth);

// ── واریز کریپتو (USDT) ───────────────────────────────────────────────────
$r->get('/wallet/deposit/crypto',  [CryptoDepositController::class, 'create'], $auth);
$r->post('/wallet/deposit/crypto', [CryptoDepositController::class, 'store'],  $secureAuth);
$r->get('/crypto-deposits',        [CryptoDepositController::class, 'index'],  $auth);

// ── برداشت ────────────────────────────────────────────────────────────────
$r->get('/wallet/withdraw',  [WithdrawalController::class, 'create'],     $auth);
$r->post('/wallet/withdraw', [WithdrawalController::class, 'store'],      $secureAuth);
$r->get('/withdrawals',      [WithdrawalController::class, 'index'],      $auth);
$r->get('/withdrawal/limits',[WithdrawalController::class, 'limitsInfo'], $auth);

// ── پرداخت آنلاین ─────────────────────────────────────────────────────────
$r->post('/payment/request',  [PaymentController::class, 'request'], $secureAuth);
$r->get('/payment/callback',  [PaymentController::class, 'callback']);
