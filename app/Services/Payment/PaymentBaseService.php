<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\LoggerInterface;
use Core\IdempotencyKey;

/**
 * PaymentBaseService - منطق مشترک برای سرویس‌های پرداخت
 */
abstract class PaymentBaseService extends \App\Services\BaseService
{
    public function __construct(
        LoggerInterface $logger,
        ?IdempotencyKey $idempotencyKey = null,
        ?\Core\Database $db = null,
        ?\Core\EventDispatcher $eventDispatcher = null
    ) {
        parent::__construct($logger, $idempotencyKey, $db, null, null, null, null, $eventDispatcher);
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
