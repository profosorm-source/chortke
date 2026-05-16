<?php

/**
 * چرتکه (Chortke) — Clean Architecture Entry Point
 */

// ── ۱. پایه ─────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('VIEW_PATH', BASE_PATH . '/views');

ob_start();
ob_implicit_flush(false);

date_default_timezone_set('Asia/Tehran');

// LOW-03 Fix: Hardening PHP Environment - Remove identifying headers
@header_remove('X-Powered-By');
@ini_set('expose_php', 'off');

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
try {
    $app->run();
} finally {
    // ── ۶. اتمام بافر ──────────────────────────────────────────────
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

