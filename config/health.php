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

];
