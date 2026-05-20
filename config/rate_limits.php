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
    /**
     * Search endpoints — DB-heavy, must be stricter than default API.
     * Section 8.8 — moved here from SearchController hardcodes.
     */
    'search' => [
        'general' => [
            'max_attempts' => env('RATE_LIMIT_SEARCH_MAX', 30),
            'decay_minutes' => 1,
        ],
        'advanced' => [
            'max_attempts' => env('RATE_LIMIT_SEARCH_ADV_MAX', 20),
            'decay_minutes' => 1,
        ],
        // Admin-side bulk search panel.
        'admin_user' => [
            'max_attempts' => env('RATE_LIMIT_ADMIN_SEARCH_USER_MAX', 50),
            'decay_minutes' => 1,
        ],
        'admin_ip' => [
            'max_attempts' => env('RATE_LIMIT_ADMIN_SEARCH_IP_MAX', 100),
            'decay_minutes' => 1,
        ],
        'admin_fingerprint' => [
            'max_attempts' => env('RATE_LIMIT_ADMIN_SEARCH_FP_MAX', 60),
            'decay_minutes' => 1,
        ],
    ],

    /**
     * Payment gateway callbacks — extremely sensitive (replay/DoS surface).
     * Section 8.8 — moved here from PaymentService hardcode (20/1m).
     */
    'payment' => [
        'callback' => [
            'max_attempts' => env('RATE_LIMIT_PAYMENT_CALLBACK_MAX', 20),
            'decay_minutes' => 1,
            'fail_closed' => true,
        ],
        'verify' => [
            'max_attempts' => env('RATE_LIMIT_PAYMENT_VERIFY_MAX', 10),
            'decay_minutes' => 1,
            'fail_closed' => true,
        ],
    ],

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
    /**
     * Section 8.8 — Centralized route → (group, endpoint) mapping.
     * Used by App\Middleware\RateLimitMiddleware to replace its
     * hardcoded ROUTE_LIMITS table. Match is done with str_starts_with.
     *
     * Order matters: most specific prefixes first.
     */
    'route_map' => [
        // Auth
        '/login'                  => ['auth', 'login'],
        '/register'               => ['auth', 'register'],
        '/forgot-password'        => ['auth', 'forgot_password'],
        '/reset-password'         => ['auth', 'reset_password'],

        // Financial
        '/wallet/deposit/crypto'  => ['financial', 'deposit'],
        '/payment/callback'       => ['payment',   'callback'],
        '/payment/verify'         => ['payment',   'verify'],
        '/payment'                => ['financial', 'deposit'],
        '/withdrawal'             => ['financial', 'withdrawal'],

        // KYC
        '/kyc'                    => ['kyc',       'submit'],

        // 2FA
        '/two-factor/verify'      => ['two_factor','verify'],
        '/two-factor'             => ['two_factor','enable'],

        // API
        '/api/auth'               => ['auth',      'login'],
        '/api/token'              => ['auth',      'login'],
        '/api/public'             => ['api',       'general'],

        // Search
        '/search/advanced'        => ['search',    'advanced'],
        '/search'                 => ['search',    'general'],

        // Reports / exports
        '/reports/export'         => ['reports',   'export'],
        '/reports'                => ['reports',   'generate'],

        // Tasks
        '/task/submit'            => ['task',      'submit'],
        '/task/dispute'           => ['task',      'dispute'],
        '/task'                   => ['task',      'execute'],

        // Admin (catch-all)
        '/admin/auth'             => ['admin',     'login'],
        '/admin'                  => ['admin',     'general'],
    ],

    /**
     * Section 8.8 — Centralized action → (group, endpoint) mapping for
     * App\Policies\RateLimitPolicy. When a FeatureFlag override is absent
     * the Policy now falls back to this mapping instead of the previous
     * restrictive "3 per 24h" lockout.
     */
    'action_map' => [
        'withdrawal'      => ['financial',  'withdrawal'],
        'manual_deposit'  => ['financial',  'deposit'],
        'crypto_deposit'  => ['financial',  'deposit'],
        'bank_card_add'   => ['financial',  'deposit'],
        'task_submit'     => ['task',       'submit'],
        'task_dispute'    => ['task',       'dispute'],
        'task_execute'    => ['task',       'execute'],
        'task_create'     => ['task',       'create'],
        'kyc_submit'      => ['kyc',        'submit'],
        'profile_update'  => ['content',    'update'],
        'password_change' => ['auth',       'reset_password'],
        'ticket_create'   => ['social',     'ticket_create'],
        'ticket_reply'    => ['social',     'ticket_reply'],
        'login'           => ['auth',       'login'],
        'register'        => ['auth',       'register'],
        'two_factor_verify'  => ['two_factor', 'verify'],
        'two_factor_enable'  => ['two_factor', 'enable'],
        'two_factor_disable' => ['two_factor', 'disable'],
        'two_factor_recovery'=> ['two_factor', 'recovery_code'],
        'payment_callback'   => ['payment', 'callback'],
        'payment_verify'     => ['payment', 'verify'],
        'admin_search_user'        => ['search', 'admin_user'],
        'admin_search_ip'          => ['search', 'admin_ip'],
        'admin_search_fingerprint' => ['search', 'admin_fingerprint'],
        'lottery_participate' => ['lottery', 'participate'],
        'referral_check_code' => ['referral', 'check_code'],
        'investment_create'   => ['investment', 'create'],
        'investment_withdraw' => ['investment', 'withdraw'],
        'content_create'      => ['content',    'create'],
        'content_update'      => ['content',    'update'],
        'content_delete'      => ['content',    'delete'],
    ],

];