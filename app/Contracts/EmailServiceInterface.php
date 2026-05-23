<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * EmailServiceInterface — قرارداد سرویس ارسال ایمیل
 */
interface EmailServiceInterface
{
    /**
     * ارسال فوری ایمیل (بدون صف)
     * 
     * @param string $toEmail آدرس ایمیل گیرنده
     * @param string $toName نام گیرنده
     * @param string $subject موضوع ایمیل
     * @param string $bodyHtml بدن ایمیل (HTML)
     * @return bool
     */
    public function sendDirect(
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyHtml
    ): bool;

    /**
     * ارسال ایمیل به صف (پردازش async)
     * 
     * @param int $userId شناسه کاربر
     * @param string $subject موضوع ایمیل
     * @param string $bodyHtml بدن ایمیل (HTML)
     * @param string|null $bodyText بدن متنی (اختیاری)
     * @param string $priority اولویت ارسال
     * @param string|null $scheduledAt زمان ارسال برنامه‌ریزی شده
     * @return string|int|null
     */
    public function enqueue(
        int     $userId,
        string  $subject,
        string  $bodyHtml,
        ?string $bodyText    = null,
        string  $priority    = 'normal',
        ?string $scheduledAt = null
    ): string|int|null;
}
