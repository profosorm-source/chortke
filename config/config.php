<?php
/**
 * تنظیمات اصلی سیستم
 * 
 * این فایل تنظیمات از .env را بارگذاری می‌کند
 */

return [
    'app' => [
        'name' => env('APP_NAME', 'Chortke'),
        'env' => env('APP_ENV', 'local'),
        'debug' => env('APP_DEBUG', false),
        'url' => env('APP_URL', 'http://localhost'),
        'base_path' => env('APP_BASE_PATH', ''),
        'timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),
        'key' => env('APP_KEY', ''),
        'trusted_proxies' => array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '127.0.0.1')))),
        'safe_mode' => env('APP_SAFE_MODE', false),
        'version' => env('APP_VERSION', '2.4.0-hardened'),
        'release' => env('APP_RELEASE', '1.0.0'),
        'encryption_key_version' => env('APP_ENCRYPTION_KEY_VERSION', 1),
        // Fix L4: لیست سفید مسیرهایی که در Safe Mode مجاز برای تغییر هستند
        'safe_mode_whitelist' => env('SAFE_MODE_WHITELIST', '/login,/logout,/verify-2fa')
            ? array_filter(array_map('trim', explode(',', (string)env('SAFE_MODE_WHITELIST', '/login,/logout,/verify-2fa'))))
            : ['/login', '/logout', '/verify-2fa'],
    ],
    
    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', 3306),
        'name' => env('DB_NAME', 'chortke'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'mysqldump_path' => env('DB_DUMP_PATH', 'mysqldump'),
        'mysql_path' => env('DB_MYSQL_PATH', 'mysql'),
    ],
    
    'session' => [
        'lifetime' => env('SESSION_LIFETIME', 7200),
        'driver' => env('SESSION_DRIVER', 'redis'),
        'fallback' => env('SESSION_FALLBACK_STORAGE', 'file'),
        'name' => 'CHORTKE_SESSION',
        // ── Fix #1: Lax برای سازگاری با OAuth callback (Google/Facebook)
        // ── Fix #2: secure بر اساس HTTPS واقعی + trusted proxy (نه فقط APP_ENV)
        'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
                     && in_array($_SERVER['REMOTE_ADDR'] ?? '', array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '127.0.0.1')))), true)),
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    
    'csrf' => [
        'token_name' => env('CSRF_TOKEN_NAME', '_csrf_token'),
        'token_length' => 64,
    ],


    'retry_policy' => [
        'max_attempts' => env('RETRY_MAX_ATTEMPTS', 3),
        'initial_delay_ms' => env('RETRY_INITIAL_DELAY_MS', 100),
        'multiplier' => env('RETRY_BACKOFF_MULTIPLIER', 2),
        'max_delay_ms' => env('RETRY_MAX_DELAY_MS', 2000),
    ],

    'circuit_breaker' => [
        'failure_threshold' => env('CIRCUIT_FAILURE_THRESHOLD', 5),
        'retry_timeout_seconds' => env('CIRCUIT_RETRY_TIMEOUT_SECONDS', 60),
    ],
    
    // ── Fix #5: feature_flags از اینجا حذف شد
    // ── منبع حقیقت واحد: config/feature_flags.php
    // ── (برای دسترسی: config_load('feature_flags')['cache_enabled'])
    
    'upload' => [
        'max_size' => env('MAX_UPLOAD_SIZE', 10485760), // 10MB
        // ── Fix #3: مسیر آپلود خارج از public (برای فایل‌های خصوصی)
        // ── UploadService خودش public/uploads vs storage/uploads را مدیریت می‌کند
        'path' => env('UPLOAD_PATH', __DIR__ . '/../storage/uploads/'),
        // ── Fix #4: allowed_videos حذف شد
        // ── UploadService منبع حقیقت واحد برای MIME های مجاز است (IMAGE_MIMES)
        // ── ویدیو توسط UploadService::DANGEROUS_EXT صریحاً رد می‌شود
    ],
    
    'mail' => [
        'driver' => env('MAIL_DRIVER', 'smtp'),
        'host' => env('MAIL_HOST'),
        'port' => env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'from' => [
            'address' => env('MAIL_FROM_ADDRESS'),
            'name' => env('MAIL_FROM_NAME'),
        ],
    ],

    
    'crypto' => [
        'usdt' => [
            'bnb20' => env('USDT_BNB20_ADDRESS'),
            'trc20' => env('USDT_TRC20_ADDRESS'),
            'erc20' => env('USDT_ERC20_ADDRESS'),
            'ton' => env('USDT_TON_ADDRESS'),
            'sol' => env('USDT_SOL_ADDRESS'),
        ],
    ],
	'captcha' => [
  'recaptcha_site_key'   => env('RECAPTCHA_SITE_KEY', ''),
  'recaptcha_secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
],

    'cors' => [
        'allowed_origins' => env('CORS_ALLOWED_ORIGINS', ''),
    ],
    
    'security' => [
        'api' => [
            'secrets' => [
                'v1' => env('SECURITY_API_TOKEN_SECRET_V1', env('SECURITY_API_TOKEN_SECRET', 'default_api_secret_v1_must_be_strong_32_chars')),
                'v2' => env('SECURITY_API_TOKEN_SECRET_V2', env('SECURITY_API_TOKEN_SECRET', 'default_api_secret_v2_must_be_strong_32_chars')),
            ],
            'current_secret_version' => env('SECURITY_API_TOKEN_CURRENT_VERSION', 'v2'),
        ]
    ],
    
    'encryption' => [
        'message_keys' => [
            1 => env('MESSAGE_ENCRYPTION_KEY_V1', base64_encode('strong_message_enc_key_v1_32bytes_long')),
        ]
    ],
];