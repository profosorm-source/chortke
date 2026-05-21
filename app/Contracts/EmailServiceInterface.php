<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * EmailServiceInterface — قرارداد سرویس ارسال ایمیل
 * 
 * این interface سرویس ارسال ایمیل را انتزاعی می‌کند و امکان:
 * - تست‌های واحد (Mock)
 * - پیاده‌سازی‌های جایگزین (مثلاً SmsService)
 * - تغییر ارائه‌دهنده ایمیل
 * 
 * را فراهم می‌آورد.
 */
interface EmailServiceInterface
{
    /**
     * ارسال فوری ایمیل (بدون صف)
     * 
     * برای ایمیل‌های حیاتی مثل:
     * - تأیید حساب
     * - بازیابی رمز عبور
     * - هشدارهای امنیتی
     * 
     * @param string $to آدرس ایمیل گیرنده
     * @param string $subject موضوع ایمیل
     * @param string $body بدن ایمیل (HTML)
     * @param array $headers هدرهای اضافی
     * @return array ['success' => bool, 'message_id' => ?string, 'error' => ?string]
     */
    public function sendDirect(
        string $to,
        string $subject,
        string $body,
        array $headers = []
    ): array;

    /**
     * ارسال ایمیل به صف (پردازش async)
     * 
     * برای ایمیل‌های عادی مثل:
     * - خوش‌آمد
     * - اطلاع‌رسانی برداشت
     * - نتایج قرعه
     * 
     * @param string $to آدرس ایمیل گیرنده
     * @param string $subject موضوع ایمیل
     * @param string $body بدن ایمیل (HTML)
     * @param array $metadata متادیتای اضافی
     * @return bool
     */
    public function enqueue(
        string $to,
        string $subject,
        string $body,
        array $metadata = []
    ): bool;

    /**
     * ارسال ایمیل به کاربر (lookup شناسه)
     * 
     * @param int $userId شناسه کاربر
     * @param string $subject موضوع ایمیل
     * @param string $body بدن ایمیل (HTML)
     * @return bool
     */
    public function sendToUser(
        int $userId,
        string $subject,
        string $body
    ): bool;

    /**
     * ارسال template ایمیل
     * 
     * @param string $to آدرس ایمیل
     * @param string $template نام template
     * @param array $variables متغیرهای template
     * @return bool
     */
    public function sendTemplate(
        string $to,
        string $template,
        array $variables = []
    ): bool;
}
