<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AdSystemContract;
use App\Contracts\LoggerInterface;
use RuntimeException;

/**
 * AdSystemManager — مدیریت یکپارچه تمام سیستم‌های تبلیغاتی
 * 
 * این کلاس با استفاده از Strategy Pattern تمام سیستم‌های تبلیغاتی را یکسان‌سازی می‌کند
 * و به Controller‌ها کمک می‌کند بدون نگرانی درباره نوع سیستم، عمل انجام دهند.
 */
class AdSystemManager extends \App\Services\BaseService
{
    private array $adapters = [];

    public function __construct(array $adapters, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->adapters = $adapters;
    }

    /**
     * دریافت Adapter برای نوع سیستم
     * 
     * @param string $type نوع سیستم (custom_task, seo, banner, ...)
     * @return AdSystemContract
     * @throws RuntimeException
     */
    public function getAdapter(string $type): AdSystemContract
    {
        if (!isset($this->adapters[$type])) {
            // M38 Fix: لاگ کردن محرمانه لیست پلتفرم‌های پشتیبانی‌شده جهت جلوگیری از افشای ساختار معماری به کاربر نهایی
            $this->logger->error('ad_system.adapter_not_found', [
                'requested_type' => $type,
                'supported_types' => array_keys($this->adapters)
            ]);
            throw new RuntimeException("نوع سیستم تبلیغاتی نامعتبر است.");
        }

        $adapter = $this->adapters[$type];
        if (!($adapter instanceof AdSystemContract)) {
            throw new RuntimeException("Adapter for type '{$type}' باید AdSystemContract را پیاده‌سازی کند");
        }

        return $adapter;
    }

    /**
     * ایجاد آگهی/تسک جدید
     */
    public function create(string $type, int $userId, array $data): array
    {
        return $this->getAdapter($type)->create($userId, $data);
    }

    /**
     * بررسی اعتبار داده‌های آگهی
     */
    public function validate(string $type, array $data, bool $isUpdate = false): array
    {
        return $this->getAdapter($type)->validate($data, $isUpdate);
    }

    /**
     * بررسی انقضای آگهی
     */
    public function isExpired(string $type, int $adId): bool
    {
        return $this->getAdapter($type)->isExpired($adId);
    }

    /**
     * محاسبه هزینه/کمیسیون سایت
     */
    public function calculateCost(string $type, float $amount, array $context = []): float
    {
        return $this->getAdapter($type)->calculateCost($amount, $context);
    }

    /**
     * پردازش پرداخت/کسب بودجه
     */
    public function processPayment(string $type, int $adId, int $userId, float $amount, string $currency): array
    {
        return $this->getAdapter($type)->processPayment($adId, $userId, $amount, $currency);
    }

    /**
     * ردیابی تعاملات
     */
    public function track(string $type, int $adId, string $eventType, ?int $userId = null): array
    {
        return $this->getAdapter($type)->track($adId, $eventType, $userId);
    }

    /**
     * دریافت وضعیت آگهی
     */
    public function getStatus(string $type, int $adId): ?array
    {
        return $this->getAdapter($type)->getStatus($adId);
    }

    /**
     * دریافت دسته‌بندی انواع سیستم‌ها
     */
    public function getSupportedTypes(): array
    {
        return array_keys($this->adapters);
    }

    /**
     * بررسی اینکه نوع پشتیبانی شده است
     */
    public function isSupported(string $type): bool
    {
        return isset($this->adapters[$type]);
    }
}
