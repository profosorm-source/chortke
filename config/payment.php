<?php
/**
 * تنظیمات درگاه‌های پرداخت
 *
 * Fix #6: مقادیر پیش‌فرض خالی (نه placeholder)
 * اگر کلید در .env تنظیم نشده باشد، null برمی‌گردد.
 * Fail-fast: PaymentService باید در boot زمان وجود کلید را validate کند.
 */

return [
    // ZarinPal
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),   // الزامی — UUID فارسی
        'sandbox'     => env('ZARINPAL_SANDBOX', false),
        
        // Endpoints
        'api_url'         => env('ZARINPAL_API_URL', 'https://api.zarinpal.com/pg/v4/payment'),
        'sandbox_url'     => env('ZARINPAL_SANDBOX_URL', 'https://sandbox.zarinpal.com/pg/rest/WebGate'),
        'payment_url'     => env('ZARINPAL_PAYMENT_URL', 'https://www.zarinpal.com/pg/StartPay'),
        'sandbox_pay_url' => env('ZARINPAL_SANDBOX_PAY_URL', 'https://sandbox.zarinpal.com/pg/StartPay'),
    ],

    // NextPay
    'nextpay' => [
        'api_key' => env('NEXTPAY_API_KEY', ''),            // الزامی
    ],

    // IDPay
    'idpay' => [
        'api_key' => env('IDPAY_API_KEY', ''),              // الزامی
        'sandbox' => env('IDPAY_SANDBOX', false),
    ],

    // DgPay (اضافه خواهد شد)
    'dgpay' => [
        'api_key' => env('DGPAY_API_KEY', ''),
    ],
];