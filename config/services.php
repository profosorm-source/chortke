<?php

/**
 * پیکربندی خدمات و سرویس‌های جانبی (Third-party Services)
 */

return [

    // سیستم ارسال اعلان پوش
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
    ],

    // درگاه ارسال پیامک
    'sms' => [
        'enabled' => filter_var(env('SMS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'provider' => env('SMS_PROVIDER', 'kavenegar'),
        'api_key' => env('SMS_API_KEY', ''),
        'from' => env('SMS_FROM', ''),
    ],

    // سرویس احراز هویت جیبیت (Jibit)
    'jibit' => [
        'api_key' => env('JIBIT_API_KEY'),
        'api_secret' => env('JIBIT_API_SECRET'),
    ],

    // سرویس هوش مصنوعی DeepFace
    'deepface' => [
        'api_url' => env('KYC_AI_SERVICE_URL'),
        'api_token' => env('KYC_AI_SERVICE_TOKEN'),
    ],

    // سرویس شناسایی موقعیت مکانی IP
    'geoip' => [
        'maxmind_license_key' => env('MAXMIND_LICENSE_KEY', ''),
    ],

];
