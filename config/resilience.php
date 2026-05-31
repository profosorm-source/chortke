<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | System-wide Retry Budget Config
    |--------------------------------------------------------------------------
    | این بخش سهمیه تلاش مجدد (Retry Budget) را برای بخش‌های مختلف سیستم تعریف می‌کند.
    | مقادیر بر اساس درصد کل درخواست‌ها محاسبه می‌شوند.
    */
    'retry_budget' => [
        // تنظیمات سراسری پیش‌فرض (Global Default)
        'global' => [
            'allowed_percentage' => 10, // حداکثر ۱۰ درصد از کل درخواست‌ها اجازه Retry دارند
            'min_allowance' => 5,      // حداقل تعداد Retry مجاز قبل از اعمال درصد (Cold start)
        ],
        // تنظیمات اختصاصی برای کانتکست‌ها و صف‌های خاص
        'contexts' => [
            'queue:high_priority' => [
                'allowed_percentage' => 20, // صف‌های با اولویت بالا سهمیه بیشتری دارند
                'min_allowance' => 10,
            ],
            'queue:notifications' => [
                'allowed_percentage' => 5,  // نوتیفیکیشن‌ها اولویت پایین‌تری برای Retry دارند
                'min_allowance' => 2,
            ],
            'service:payment_gateway' => [
                'allowed_percentage' => 15,
                'min_allowance' => 3,
            ]
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Config
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'default' => [
            'threshold' => 5,          // تعداد خطاهای متوالی برای باز شدن مدار
            'timeout' => 60,           // زمان باز ماندن مدار به ثانیه
        ],
        'services' => [
            'payment_gateway' => [
                'threshold' => 3,
                'timeout' => 120,      // توقف ۲ دقیقه‌ای در صورت ۳ خطای متوالی بانکی
            ],
            'crypto_node' => [
                'threshold' => 10,
                'timeout' => 300,
            ]
        ]
    ]
];
