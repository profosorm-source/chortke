<?php
/**
 * Feature Flags Configuration
 * 
 * فعال/غیرفعال کردن ویژگی‌های سایت
 */

return [
    // قرعه‌کشی
    'lottery' => [
        'enabled' => env('FEATURE_LOTTERY_ENABLED', false),
        'min_participants' => env('LOTTERY_MIN_PARTICIPANTS', 10),
        'max_participants' => env('LOTTERY_MAX_PARTICIPANTS', 1000),
        'entry_price' => env('LOTTERY_ENTRY_PRICE', 10000), // تومان
    ],
    
    // سرمایه‌گذاری
    'investment' => [
        'enabled' => env('FEATURE_INVESTMENT_ENABLED', false),
        'min_amount' => env('INVESTMENT_MIN_AMOUNT', 100000),
        'max_amount' => env('INVESTMENT_MAX_AMOUNT', 10000000),
        'commission_rate' => env('INVESTMENT_COMMISSION_RATE', 10), // درصد
    ],
    
    // سفارش استوری
    'story_promotion' => [
        'enabled' => env('FEATURE_STORY_PROMOTION_ENABLED', false),
        'min_followers' => env('STORY_MIN_FOLLOWERS', 1000),
        'commission_rate' => env('STORY_COMMISSION_RATE', 20), // درصد
    ],
    
    // تسک‌ها
    'tasks' => [
        'enabled' => true,
        'platforms' => [
            'instagram' => true,
            'telegram' => true,
            'youtube' => true,
            'twitter' => true,
            'tiktok' => false,
        ],
    ],
    
    // کسب درآمد از استعداد
    'content_monetization' => [
        'enabled' => env('FEATURE_CONTENT_ENABLED', false),
        'min_views' => env('CONTENT_MIN_VIEWS', 100),
        'commission_rate' => env('CONTENT_COMMISSION_RATE', 30), // درصد
    ],
    
    // سیستم ارجاع
    'referral' => [
        'enabled' => true,
        'commission_rates' => [
            'tasks' => 10, // درصد
            'investment' => 5,
            'lottery' => 3,
            'content' => 5,
        ],
    ],
    
    // سیستم تخفیف و کوپن
    'coupons' => [
        'enabled' => env('FEATURE_COUPONS_ENABLED', false),
    ],

    // OAuth security extensions
    'oauth_strict_ip_binding' => [
        'enabled' => false,
        'description' => 'فعال‌سازی strict IP binding برای ورود اجتماعی (OAuth) در محیط‌های امنیتی خاص. این گزینه برای جلوگیری از تکمیل callback توسط IP متفاوت استفاده می‌شود.',
    ],
    
    // کیف پول رمزارز
    'crypto_wallet' => [
        'enabled' => env('FEATURE_CRYPTO_ENABLED', false),
        'networks' => [
            'bnb20' => true,
            'trc20' => true,
            'erc20' => false,
            'ton' => false,
            'sol' => false,
        ],
    ],
    
    // ویژگی‌های آزمایشی (Beta)
    'beta' => [
        'ai_task_verification' => false,
        'auto_kyc_verification' => false,
        'gamification' => false,
    ],

    // کلید امنیتی دسترسی به API سوئیچ‌ها
    'api_key' => env('FEATURE_FLAG_API_KEY', 'FF_SECURE_KEY_REQUIRED_MIN_32_CHARS'),
];