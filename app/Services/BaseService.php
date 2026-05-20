<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Traits\ClientInfoTrait;
use Core\Exceptions\ValidationException;

abstract class BaseService
{
    use ClientInfoTrait;

    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function logInfo(string $event, array $context = []): void
    {
        $this->logger->info($event, $context);
    }

    protected function logWarning(string $event, array $context = []): void
    {
        $this->logger->warning($event, $context);
    }

    /**
     * logError - Accepts mixed context and normalizes it to standard array structure.
     */
    protected function logError(string $event, mixed $context = []): void
    {
        if (!is_array($context)) {
            $context = ['context' => $context];
        }
        $this->logger->error($event, $context);
    }

    /**
     * Log system exceptions centralizing formatting.
     */
    protected function logException(\Throwable $e, string $event = ''): void
    {
        $this->logError($event ?: 'exception_caught', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => config('app.debug') ? array_slice($e->getTrace(), 0, 5) : '[hidden in production]',
        ]);
    }

    /**
     * Run validations and either throw or return the collection of errors.
     */
    protected function guardValidation(array $validationResult, bool $throw = true): ?array
    {
        if (empty($validationResult['valid'])) {
            if ($throw) {
                throw new ValidationException($validationResult['errors']);
            }
            return $validationResult['errors'];
        }
        return null;
    }

    protected function formatValidationErrors(array $errors): string
    {
        return implode('; ', array_map(function ($error) {
            if (is_array($error)) {
                return implode(', ', $error);
            }
            return (string)$error;
        }, $errors));
    }

    protected function successResponse(string $message = '', array $data = []): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];
    }

    protected function errorResponse(string $message = '', array $errors = [], int $statusCode = 400): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'status_code' => $statusCode,
        ];
    }

    /**
     * Standardized use-case result helpers for new code paths.
     * Legacy successResponse/errorResponse remain for backward compatibility.
     */
    protected function ok(array $data = [], string $message = ''): array
    {
        return [
            'ok' => true,
            'success' => true,
            'data' => $data,
            'message' => $message,
            'error' => null,
        ];
    }

    protected function fail(string $message = '', array $errors = [], int $statusCode = 400): array
    {
        return [
            'ok' => false,
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => $errors,
            'status_code' => $statusCode,
        ];
    }

    /**
     * 🚀 UPG-02: اجرای توابع با منطق تلاش مجدد (Retry Logic) جهت مواجهه با خطاهای لحظه‌ای
     * MED-02 Fix: ممانعت از تکرار بیهوده خطاهای منطقی غیرقابل جبران با فیلتر کردن کلاس‌های هدف
     */
    protected function withRetry(callable $fn, int $times = 3, int $sleepMs = 100, array $retryOn = []): mixed
    {
        $attempts = 0;
        while ($attempts < $times) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                // اگر کلاس‌های خاصی مشخص شده، در صورت عدم مطابقت فورا خطا را شلیک کن
                if (!empty($retryOn)) {
                    $matches = false;
                    foreach ($retryOn as $class) {
                        if ($e instanceof $class) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        throw $e;
                    }
                }

                $attempts++;
                if ($attempts >= $times) {
                    $this->logger->error('operation_retry_failed', [
                        'attempts' => $attempts,
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
                usleep($sleepMs * 1000);
            }
        }
        return null;
    }

    // =========================================================================
    // Section 8.2 — Unified idempotency wrapper
    // =========================================================================
    //
    // یک thin wrapper روی Core\IdempotencyKey::run() که در همه‌ی سرویس‌های
    // فرزند BaseService در دسترس است. هدف: استفاده‌ی یکپارچه و خوانا، بدون
    // ساخت سرویس جدید.
    //
    // اگر $key داده نشود، به‌صورت deterministic از scope + actorId + payload
    // ساخته می‌شود.
    //
    // نمونه:
    //   return $this->idempotent('rating.submit', $raterId, [
    //       'ref' => $refType . ':' . $refId,
    //   ], function () use (...) {
    //       // business logic
    //       return $this->ok(['rating_id' => $id]);
    //   });

    /**
     * @template T
     * @param string         $scope
     * @param int            $actorId
     * @param array          $payload    داده‌هایی که عمل را منحصربه‌فرد می‌کنند
     * @param callable():T   $callback
     * @param string|null    $explicitKey  در صورت ارسال، جایگزین payload-based key می‌شود
     * @return T
     */
    protected function idempotent(
        string $scope,
        int $actorId,
        array $payload,
        callable $callback,
        ?string $explicitKey = null
    ): mixed {
        try {
            $service = \Core\Container::getInstance()->make(\Core\IdempotencyKey::class);
        } catch (\Throwable $e) {
            $this->logger->warning('idempotency.unavailable_fallback', [
                'scope' => $scope,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }

        if ($explicitKey !== null && $explicitKey !== '') {
            $key = $explicitKey;
        } else {
            $key = $service->keyFromPayload($scope, $payload);
        }

        return $service->run($scope, $actorId, $key, $callback, $payload);
    }
}