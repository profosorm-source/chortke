<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\LoggerInterface;
use App\Contracts\ValidatorFactoryInterface;
use Core\Exceptions\ValidationException;
use App\Services\Settings\AppSettings;

/**
 * AdapterBase - پایه مشترک برای همه آداپترهای خارجی
 *
 * وظایف:
 * - تزریق LoggerInterface
 * - تزریق SettingService
 * - استانداردسازی response shape
 * - wrapper برای validation
 * - logging امن و یکپارچه
 */
abstract class AdapterBase
{
    use \App\Traits\ExternalCallTrait;

    protected LoggerInterface $logger;
    protected AppSettings $appSettings;
    protected ?ValidatorFactoryInterface $validatorFactory;

    public function __construct(LoggerInterface $logger, AppSettings $appSettings, ?ValidatorFactoryInterface $validatorFactory = null)
    {
        $this->logger = $logger;
        $this->appSettings = $appSettings;
        $this->validatorFactory = $validatorFactory;
    }

    /**
     * Wrapper استاندارد برای validation
     * اکنون می‌تواند هم فلگ bool (برود به validate فرزند) و هم یک آرایه مستقیم rules بگیرد.
     */
    protected function validateData(array $data, mixed $rulesOrUpdate = false): void
    {
        // 1. اگر مستقیماً آرایه‌ای از Rules ارسال شده، خودکار با Validator سیستمی ولیدیت کن
        if (is_array($rulesOrUpdate)) {
            $validator = $this->validatorFactory
                ? $this->validatorFactory->make($data, $rulesOrUpdate)
                : new \Core\Validator($data, $rulesOrUpdate);
            if (!$validator->passes()) {
                throw new ValidationException($validator->errors());
            }
            return;
        }

        // 2. وگرنه از سیستم کلاس فرزند استفاده کن
        $result = $this->validate($data, (bool)$rulesOrUpdate);
        if (!$result['valid']) {
            throw new ValidationException($result['errors'] ?? ['دیتا نامعتبر است.']);
        }
    }

    /**
     * Response shape استاندارد برای موفقیت
     */
    protected function successResponse(string $message, array $data = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * Response shape استاندارد برای خطا
     */
    protected function errorResponse(string $message, mixed $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => is_array($errors) ? $errors : [$errors],
        ];
    }

    /**
     * Logging استاندارد برای عملیات موفق
     */
    protected function logSuccess(string $operation, array $context = []): void
    {
        $this->logger->info("adapter.{$this->getType()}.{$operation}.success", $context);
    }

    /**
     * Logging استاندارد اطلاعات عمومی (مورد نیاز فرزندان)
     */
    protected function logInfo(string $operation, array $context = []): void
    {
        $this->logger->info("adapter.{$this->getType()}.{$operation}.info", $context);
    }

    /**
     * Logging استاندارد برای خطا
     */
    protected function logError(string $operation, string $error, array $context = []): void
    {
        $this->logger->error("adapter.{$this->getType()}.{$operation}.failed", array_merge($context, ['error' => $error]));
    }

    /**
     * Logging استاندارد برای عملیات شروع
     */
    protected function logStart(string $operation, array $context = []): void
    {
        $this->logger->info("adapter.{$this->getType()}.{$operation}.started", $context);
    }

    /**
     * متد abstract برای نوع آداپتر
     */
    abstract public function getType(): string;

    /**
     * متد abstract برای validation
     */
    abstract public function validate(array $data, bool $isUpdate = false): array;
}

