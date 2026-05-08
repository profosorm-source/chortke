<?php

namespace App\Services\Payment;

use App\Contracts\LoggerInterface;

/**
 * PaymentBaseService - منطق مشترک برای سرویس‌های پرداخت
 */
abstract class PaymentBaseService extends \App\Services\BaseService
{
    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
    }

    /**
     * اعتبارسنجی مشترک مبلغ
     */
    protected function validateAmount(float $amount): array
    {
        $errors = [];

        if ($amount <= 0) {
            $errors[] = 'مبلغ باید بزرگتر از صفر باشد';
        }

        if ($amount < 1000) {
            $errors[] = 'حداقل مبلغ ۱۰۰۰ تومان است';
        }

        if ($amount > 50000000) { // ۵۰ میلیون تومان
            $errors[] = 'حداکثر مبلغ ۵۰ میلیون تومان است';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Logging استاندارد برای عملیات موفق
     */
    protected function logSuccess(string $operation, array $context): void
    {
        $this->logger->info("payment.{$operation}.success", $context);
    }

    /**
     * Logging استاندارد برای خطا
     */
    protected function logPaymentError(string $operation, string $error, array $context = []): void
    {
        $this->logger->error("payment.{$operation}.failed", array_merge($context, ['error' => $error]));
    }

    /**
     * Logging استاندارد برای عملیات شروع
     */
    protected function logStart(string $operation, array $context): void
    {
        $this->logger->info("payment.{$operation}.started", $context);
    }
}
