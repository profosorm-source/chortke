<?php

/**
 * چرتکه (Chortke) — Clean Architecture Entry Point
 */

// ── ۱. پایه ─────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('VIEW_PATH', BASE_PATH . '/views');

// ── Hardened Security Defaults ──────────────────────────────────
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

ob_start();
ob_implicit_flush(false);

date_default_timezone_set('Asia/Tehran');

// ── ۲. بارگذاری محیط و هسته سیستم ──────────────────────────────
// تمام منطق‌های امنیتی شامل CORS، HTTPS و هدرها به صورت معماری سراسری (Middleware)
// به داخل هسته اپلیکیشن و Router منتقل شده‌اند.
require_once BASE_PATH . '/bootstrap/app.php';

// ── ۳. اجرای Application ────────────────────────────────────────
// Application::getInstance() مدیریت سشن، پایگاه داده و سرویس کانتینر را برعهده می‌گیرد.
$app = \Core\Application::getInstance();

// ── ۴. بارگذاری مسیرها (Routes) ────────────────────────────────
require_once BASE_PATH . '/routes/routes.php';

// ── ۵. اجرای نهایی و ارسال به کاربر ─────────────────────────────
// تمامی میدل‌ویرهای سراسری در متد dispatch به طور خودکار اعمال می‌شوند.
$app->run();

// ── ۶. اتمام بافر ──────────────────────────────────────────────
ob_end_flush();
