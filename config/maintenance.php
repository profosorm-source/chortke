<?php
/**
 * Maintenance Mode Configuration
 * 
 * تنظیمات حالت تعمیر
 */

return [
    // فعال/غیرفعال بودن حالت تعمیر
    'enabled' => env('MAINTENANCE_MODE', false),
    
    // IP های مجاز برای دسترسی در حالت تعمیر
    'allowed_ips' => array_filter(
        array_map('trim', explode(',', env('MAINTENANCE_ALLOWED_IPS', '127.0.0.1,::1')))
    ),
    
    // پیام نمایشی
    'message' => env('MAINTENANCE_MESSAGE', 'ما در حال بهبود سیستم هستیم. لطفاً کمی صبر کنید.'),
    
    // زمان تخمینی پایان (اختیاری)
    'retry_after' => env('MAINTENANCE_RETRY_AFTER', 3600), // ثانیه
    
    // نمایش زمان باقیمانده
    'show_timer' => env('MAINTENANCE_SHOW_TIMER', true),
    
    // اجازه دسترسی به ادمین‌ها
    'allow_admins' => true,
    
    // Fix #7: به‌جای wildcard '/api/*' (که ممکن است matcher support نکند)،
    // از prefix ثابت استفاده می‌شود.
    // Middleware باید با str_starts_with($path, $prefix) تطابق دهد.
    'except' => [
        '/login',
        '/api',      // هر مسیری که با /api شروع شود (prefix match در middleware)
        '/health',
        '/ping',
    ],
];