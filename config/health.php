<?php

/**
 * Health Check Configuration
 *
 * تنظیمات نقطه پایان بررسی سلامت سیستم
 */

return [

    // IP های مجاز برای دریافت وضعیت سلامت
    'allowed_ips' => array_filter(array_map('trim', explode(',', env('HEALTH_ALLOWED_IPS', '127.0.0.1,::1')))),

    // توکن امنیتی جایگزین برای احراز هویت ابزارهای مانیتورینگ خارجی
    'check_token' => env('HEALTH_CHECK_TOKEN', ''),
    
    // زمان توقف (Timeout)
    'timeout' => (int)env('HEALTH_CHECK_TIMEOUT', 5),
    
    // بررسی وابستگی‌ها
    'dependencies' => [
        'database' => ['enabled' => true, 'timeout' => 2],
        'redis' => ['enabled' => true, 'timeout' => 1],
        'queue' => ['enabled' => false],
    ],
    
    // آستانه‌های مجاز مانیتورینگ
    'thresholds' => [
        'disk_usage' => (int)env('HEALTH_DISK_THRESHOLD', 90),
        'memory_usage' => (int)env('HEALTH_MEMORY_THRESHOLD', 85),
        'cpu_load' => (int)env('HEALTH_CPU_THRESHOLD', 80),
    ],
    
    // تنظیمات ارسال هشدار (Alerts)
    'alerts' => [
        'slack_webhook' => env('HEALTH_SLACK_WEBHOOK'),
        'email' => env('HEALTH_ALERT_EMAIL'),
    ],

];
