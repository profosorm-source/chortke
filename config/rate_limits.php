<?php
/**
 * Rate Limiting Configuration
 * 
 * تنظیمات محدودیت درخواست برای endpoint های مختلف
 * هر endpoint می‌تواند تنظیمات خاص خودش را داشته باشد
 * 
 * SECURITY NOTES:
 * - Admin operations have appropriate rate limits (not overly permissive)
 * - Critical endpoints use fail-closed rate limiting
 * - Rate limits are configured per-action for granular control
 */

return [
    /**
     * تنظیمات پیش‌فرض
     * اگر برای endpoint خاصی تنظیم نشده باشد، این مقادیر استفاده می‌شود
     */
    'default' => [
        'max_attempts' => env('RATE_LIMIT_MAX_ATTEMPTS', 60),
        'decay_minutes' => env('RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /**
     * Authentication & Security Endpoints
     */
    'auth' => [
        'login' => [
            'max_attempts' => env('RATE_LIMIT_LOGIN_MAX', 5),
            'decay_minutes' => env('RATE_LIMIT_LOGIN_DECAY', 5),
            'message' => 'تعداد تلاش‌های ورود بیش از حد مجاز. لطفاً کمی صبر کنید.'
        ],
        'register' => [
            'max_attempts' => env('RATE_LIMIT_REGISTER_MAX', 3),
            'decay_minutes' => env('RATE_LIMIT_REGISTER_DECAY', 60),
        ],
        'forgot_password' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
        'reset_password' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Financial Operations
     */
    'financial' => [
        'deposit' => [
            'max_attempts' => env('RATE_LIMIT_DEPOSIT_MAX', 10),
            'decay_minutes' => 60,
        ],
        'withdrawal' => [
            'max_attempts' => env('RATE_LIMIT_WITHDRAWAL_MAX', 5),
            'decay_minutes' => 60,
        ],
    ],

    /**
     * API Endpoints
     */
    'api' => [
        'general' => [
            'max_attempts' => env('RATE_LIMIT_API_MAX', 100),
            'decay_minutes' => 1,
        ],
        'authenticated' => [
            'max_attempts' => env('RATE_LIMIT_API_AUTH_MAX', 200),
            'decay_minutes' => 1,
        ],
    ],

    /**
     * Task & Execution
     * محدودیت‌های تسک‌ها
     */
    'task' => [
        'create' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
            'message' => 'تعداد ایجاد تسک بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'execute' => [
            'max_attempts' => 50,
            'decay_minutes' => 60,
            'message' => 'تعداد اجرای تسک بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'submit' => [
            'max_attempts' => 30,
            'decay_minutes' => 60,
        ],
        'dispute' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Social & Communication
     * محدودیت‌های ارتباطی
     */
    'social' => [
        'comment' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
        'message' => [
            'max_attempts' => 30,
            'decay_minutes' => 60,
        ],
        'ticket_create' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
            'message' => 'تعداد ایجاد تیکت بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'ticket_reply' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Admin Operations
     * محدودیت‌های ادمین (سخت‌گیرانه‌تر از کاربران عادی برای امنیت)
     * 
     * LOW-03 Fix: Reduced admin.general from 500 to 100 requests per minute
     * Previous value of 500 was dangerously high and could allow DoS attacks
     * A value of 100 is reasonable for admin operations while still
     * accommodating legitimate bulk operations.
     */
    'admin' => [
        'login' => [
            'max_attempts' => 3,
            'decay_minutes' => 10,
            'message' => 'تعداد تلاش‌های ورود ادمین بیش از حد. لطفاً 10 دقیقه صبر کنید.'
        ],
        // LOW-03 Fix: Reduced from 500 to 100 requests per minute
        // This prevents abuse while still allowing legitimate admin operations
        'general' => [
            'max_attempts' => 100,  // Reduced from 500 for security
            'decay_minutes' => 1,
            'message' => 'تعداد درخواست‌های ادمین بیش از حد مجاز است.'
        ],
        // Admin 2FA verification is stricter than user 2FA
        '2fa_verify' => [
            'max_attempts' => 3,
            'decay_minutes' => 10,
            'message' => 'تعداد تلاش‌های تایید 2FA ادمین بیش از حد. لطفاً 10 دقیقه صبر کنید.'
        ],
    ],

    /**
     * Search & Browse
     * محدودیت‌های جستجو
     */
    'search' => [
        'general' => [
            'max_attempts' => 30,
            'decay_minutes' => 1,
        ],
        'advanced' => [
            'max_attempts' => 20,
            'decay_minutes' => 1,
        ],
    ],

    /**
     * Content Creation
     * محدودیت‌های ایجاد محتوا
     */
    'content' => [
        'create' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
        'update' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
        'delete' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Reports & Analytics
     * محدودیت‌های گزارش‌گیری
     */
    'reports' => [
        'generate' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
            'message' => 'تعداد درخواست گزارش بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'export' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * KYC & Verification
     * محدودیت‌های احراز هویت
     */
    'kyc' => [
        'submit' => [
            'max_attempts' => 3,
            'decay_minutes' => 1440, // 24 ساعت
            'message' => 'تعداد ارسال مدارک احراز هویت بیش از حد. لطفاً 24 ساعت صبر کنید.'
        ],
        'update' => [
            'max_attempts' => 5,
            'decay_minutes' => 1440,
        ],
    ],

    /**
     * Investment Operations
     * محدودیت‌های سرمایه‌گذاری
     */
    'investment' => [
        'create' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
        'withdraw' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Lottery & Games
     * محدودیت‌های قرعه‌کشی
     */
    'lottery' => [
        'participate' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
        'vote' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Referral System
     * محدودیت‌های سیستم دعوت
     */
    'referral' => [
        'check_code' => [
            'max_attempts' => 30,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Two-Factor Authentication
     * محدودیت‌های 2FA
     */
    'two_factor' => [
        'verify' => [
            'max_attempts' => 5,
            'decay_minutes' => 10,
            'message' => 'تعداد تلاش‌های تایید 2FA بیش از حد. لطفاً 10 دقیقه صبر کنید.'
        ],
        'enable' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
        'disable' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
        // MEDIUM-M-14 Fix: Separate rate limit for recovery code attempts
        // Recovery codes are high-value credentials and need stricter limits
        'recovery_code' => [
            'max_attempts' => 3,
            'decay_minutes' => 5,  // 5 minutes instead of 10 for TOTP
            'message' => 'تعداد تلاش‌های کد بازیابی بیش از حد. لطفاً 5 دقیقه صبر کنید.'
        ],
    ],
];