<?php

declare(strict_types=1);

namespace App\Services\Adapters;

use App\Contracts\LoggerInterface;
use Core\Exceptions\ValidationException;
use App\Services\SettingService;

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
    protected LoggerInterface $logger;
    protected SettingService $settingService;

    public function __construct(LoggerInterface $logger, SettingService $settingService)
    {
        $this->logger = $logger;
        $this->settingService = $settingService;
    }

    /**
     * Wrapper استاندارد برای validation
     */
    protected function validateData(array $data, bool $isUpdate = false): void
    {
        $result = $this->validate($data, $isUpdate);
        if (!$result['valid']) {
            throw new ValidationException($result['errors']);
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
    protected function errorResponse(string $message, array $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
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