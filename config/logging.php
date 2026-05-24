<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */

    // مسیر ذخیره لاگ‌ها
    'log_dir' => dirname(__DIR__) . '/storage/logs/',

    // لاگ به فایل
    'log_to_file' => env('LOG_TO_FILE', true),

    // Fix #8: لاگ به دیتابیس — env-driven (پیش‌فرض false برای جلوگیری از bottleneck)
    // در production فقط وقتی DB می‌تواند بار را تحمل کند true شود
    'log_to_database' => env('LOG_TO_DATABASE', false),

    // نرخ نمونه‌گیری (sampling) برای لاگ دیتابیس (1-100)
    // مثال: 25 = فقط 25٪ از لاگ‌ها در DB ثبت می‌شوند
    'database_sample_rate' => max(1, min(100, (int)env('LOG_DB_SAMPLE_RATE', 100))),

    // آیا لاگ async (queue) باشد؟ (کاهش تأثیر روی request latency)
    'async_logging' => env('LOG_ASYNC', false),

    // حداقل سطح لاگ
    'min_level' => env('LOG_LEVEL', 'info'),

    // سطوح لاگ که باید در دیتابیس ذخیره شوند
    'database_levels' => ['emergency', 'alert', 'critical', 'error'],

    // تعداد روزهای نگهداری لاگ‌ها
    'retention_days' => [
        'file' => 30,       // فایل‌های لاگ
        'database' => 90,   // لاگ‌های دیتابیس
        'activity' => 90,   // فعالیت‌ها
        'audit' => 365,     // audit trail (یک سال)
    ],

    // حداکثر سایز فایل لاگ (MB)
    'max_file_size' => 10,

    // فرمت لاگ
    'format' => '[{timestamp}] [{level}] {message} {context}',

    // نرخ throttle برای لاگ‌های غیر بحرانی در production
    'throttle' => [
        'debug_per_minute' => (int)env('LOG_THROTTLE_DEBUG_PER_MINUTE', 30),
        'info_per_minute' => (int)env('LOG_THROTTLE_INFO_PER_MINUTE', 60),
        'warning_per_minute' => (int)env('LOG_THROTTLE_WARNING_PER_MINUTE', 300),
    ],

    // فعال/غیرفعال کردن لاگ در محیط‌های مختلف
    'enabled' => [
        'production' => true,
        'staging' => true,
        'development' => true,
        'testing' => false,
    ],

    // لاگ خودکار برای eventهای خاص
    'auto_log' => [
        'login' => true,
        'logout' => true,
        'failed_login' => true,
        'password_reset' => true,
        'kyc_submit' => true,
        'kyc_approve' => true,
        'deposit' => true,
        'withdrawal' => true,
    ],
    // تنظیمات پایش عملکرد و کوئری‌های کند
    'performance' => [
        'log_performance' => filter_var(env('LOG_PERFORMANCE', true), FILTER_VALIDATE_BOOLEAN),
        'performance_threshold_ms' => (float)env('LOG_PERFORMANCE_THRESHOLD', 500),
        'slow_query_threshold' => (float)env('SLOW_QUERY_THRESHOLD', 1.0), // ثانیه
        'log_slow_queries' => (bool)env('LOG_SLOW_QUERIES', true),
    ],
];
