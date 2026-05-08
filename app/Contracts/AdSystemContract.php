<?php

namespace App\Contracts;

/**
 * Contract برای تمام سیستم‌های تبلیغاتی
 * 
 * این interface تمام سیستم‌های تبلیغاتی را یکسان‌سازی می‌کند:
 * - CustomTask, SeoAd, Banner, Vitrine, StoryPromotion, AdTube/Influencer
 */
interface AdSystemContract
{
    /**
     * ایجاد آگهی/تسک جدید
     * 
     * @param int $userId کاربر ایجادکننده
     * @param array $data داده‌های آگهی
     * @return array ['success' => bool, 'id' => int|null, 'message' => string]
     */
    public function create(int $userId, array $data): array;

    /**
     * بررسی اعتبار داده‌های آگهی
     * 
     * @param array $data داده‌های آگهی
     * @param bool $isUpdate آیا این یک بروزرسانی است؟
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate(array $data, bool $isUpdate = false): array;

    /**
     * بررسی انقضای آگهی
     * 
     * @param int $adId شناسه آگهی
     * @return bool
     */
    public function isExpired(int $adId): bool;

    /**
     * محاسبه هزینه/کمیسیون سایت
     * 
     * @param float $amount مبلغ اولیه
     * @param array $context متادیتای اضافی
     * @return float
     */
    public function calculateCost(float $amount, array $context = []): float;

    /**
     * پردازش پرداخت/کسب بودجه
     * 
     * @param int $adId شناسه آگهی
     * @param int $userId آیدی کاربر
     * @param float $amount مبلغ
     * @param string $currency واحد پول
     * @return array ['success' => bool, 'transaction_id' => int|null, 'message' => string]
     */
    public function processPayment(int $adId, int $userId, float $amount, string $currency): array;

    /**
     * ردیابی تعاملات (کلیک، نمایش، تکمیل)
     * 
     * @param int $adId شناسه آگهی
     * @param string $eventType نوع رویداد (click, view, complete)
     * @param int|null $userId آیدی کاربر (اختیاری)
     * @return array ['success' => bool, 'message' => string]
     */
    public function track(int $adId, string $eventType, ?int $userId = null): array;

    /**
     * دریافت وضعیت آگهی
     * 
     * @param int $adId شناسه آگهی
     * @return array|null
     */
    public function getStatus(int $adId): ?array;

    /**
     * دریافت نوع سیستم
     * 
     * @return string
     */
    public function getType(): string;
}
