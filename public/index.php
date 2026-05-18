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

// HARDENING-01: Set maximum execution timeout for safety
if (PHP_SAPI !== 'cli' && function_exists('set_time_limit')) {
    @set_time_limit(30);
}

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
} catch (\Throwable $e) {
    // ── ۶. سپر نهایی در برابر نشت خطا (Ultimate Safety Net) ────────
    // در این لایه بیرونی، ما فرض می‌کنیم کل سیستم (کانتینر، سرویس‌ها و کانفیگ) ممکن است خراب شده باشند.
    // بنابراین بدون هیچ وابستگی به توابع فریم‌ورک، با استفاده از قابلیت‌های نیتیو PHP خطا را مدیریت می‌کنیم.
    http_response_code(500);
    
    // پاکسازی کامل بافرهای خروجی باز برای جلوگیری از رندر صفحات شکسته یا اطلاعات ناقص
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // ثبت خطای بحرانی سیستمی در سیستم لاگ سرور (عاری از وابستگی به فریم‌ورک)
    error_log('Critical Bootstrapping Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    error_log($e->getTraceAsString());

    // خواندن مستقل فایل env. جهت جلوگیری از فراخوانی توابع کمکی فریم‌ورک در حالت خرابی
    $isDebug = false;
    if (defined('BASE_PATH')) {
        $envFile = BASE_PATH . '/.env';
        if (file_exists($envFile)) {
            $envContent = @file_get_contents($envFile);
            if ($envContent !== false && preg_match('/^\s*APP_DEBUG\s*=\s*(true|1)\s*$/mi', $envContent)) {
                $isDebug = true;
            }
        }
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    if ($isDebug) {
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>خطای بحرانی سیستم (حالت توسعه)</title>';
        echo '<style>body{font-family:Tahoma,sans-serif;padding:30px;background:#fcfcfc;} .box{background:#fff;border-right:5px solid #e74c3c;padding:25px;box-shadow:0 5px 20px rgba(0,0,0,0.05);border-radius:4px;overflow-x:auto;} pre{background:#f8f9fa;padding:15px;border:1px solid #eee;border-radius:4px;direction:ltr;text-align:left;}</style></head>';
        echo '<body><div class="box"><h2>خطای بحرانی راه‌اندازی سیستم</h2>';
        echo '<p><strong>خطا:</strong> ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p><strong>فایل:</strong> ' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ' (خط ' . $e->getLine() . ')</p>';
        echo '<h3>درخت فراخوانی (Stack Trace):</h3><pre>' . htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre></div></body></html>';
    } else {
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>خطای موقت سیستم</title>';
        echo '<style>body{font-family:Tahoma,sans-serif;padding:50px;background:#fcfcfc;text-align:center;} .box{display:inline-block;background:#fff;border-top:5px solid #e74c3c;padding:40px;box-shadow:0 5px 20px rgba(0,0,0,0.05);border-radius:4px;max-width:500px;}</style></head>';
        echo '<body><div class="box"><h2>بروز خطای موقت در سیستم</h2>';
        echo '<p>در حال حاضر سیستم با یک خطای غیرمنتظره مواجه شده است. تیم فنی ما از موضوع مطلع شده است. لطفاً چند لحظه دیگر دوباره تلاش کنید.</p></div></body></html>';
    }
    exit(1);
} finally {
    // ── ۷. اتمام بافر در شرایط عادی ─────────────────────────────
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}

