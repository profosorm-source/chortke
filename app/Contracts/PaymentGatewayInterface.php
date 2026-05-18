<?php

namespace App\Contracts;

/**
 * PaymentGatewayInterface - قرارداد مشترک برای همه پردازشگرهای پرداخت
 *
 * این interface تضمین می‌کند که همه gatewayها متدهای یکسانی داشته باشند
 */
interface PaymentGatewayInterface
{
    /**
     * ایجاد پرداخت جدید
     *
     * @param float $amount مبلغ
     * @param string $description توضیحات
     * @param string $callbackUrl آدرس بازگشت
     * @param array $options اختیاری (ایمیل، موبایل و ...)
     * @return array نتیجه شامل success, authority, message
     */
    public function createPayment(float $amount, string $description, string $callbackUrl, array $options = []): array;

    /**
     * بررسی وضعیت پرداخت
     *
     * @param string $authority شناسه پرداخت
     * @param float $amount مبلغ تراکنش جهت تطابق
     * @return array نتیجه شامل success, status, amount, refId
     */
    public function verifyPayment(string $authority, float $amount): array;

    /**
     * تایید اعتبار callback دریافتی از gateway
     *
     * @param array $callbackData داده‌های callback
     * @return bool true اگر callback معتبر باشد
     */
    public function verifyCallback(array $callbackData): bool;

    /**
     * برگرداندن پرداخت (در صورت امکان)
     *
     * @param string $authority شناسه پرداخت
     * @return array نتیجه برگرداندن
     */
    public function refundPayment(string $authority): array;

    /**
     * نام gateway
     */
    public function getName(): string;

    /**
     * آیا gateway فعال است
     */
    public function isActive(): bool;
}