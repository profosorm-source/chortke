<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use App\Traits\ClientInfoTrait;
use Core\Exceptions\ValidationException;
use Core\IdempotencyKey;

abstract class BaseService
{
    use ClientInfoTrait;

    protected LoggerInterface $logger;
    protected ?IdempotencyKey $idempotencyKey;

    public function __construct(LoggerInterface $logger, ?IdempotencyKey $idempotencyKey = null)
    {
        $this->logger = $logger;
        $this->idempotencyKey = $idempotencyKey;
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

    /**
     * یکپارچه‌سازی فرآیند ساخت، اجرا و پردازش خروجی اعتبارسنجی
     */
    protected function validate(array $data, array $rules, array $messages = [], bool $throw = true): ?array
    {
        $validator = app(\App\Contracts\ValidatorFactoryInterface::class)->make($data, $rules, $messages, app(\Core\Database::class));
        $valid = $validator->validate();
        
        return $this->guardValidation([
            'valid' => $valid,
            'errors' => $validator->getErrors()
        ], $throw);
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
        \Core\RetryPolicy::recordAttempt();
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
                
                // Enforce System-wide Retry Budget to avoid retry storms
                if (!\Core\RetryPolicy::acquireRetryBudget()) {
                    $this->logger->critical('operation_retry_aborted_budget', [
                        'attempts' => $attempts,
                        'error' => $e->getMessage()
                    ]);
                    throw new \RuntimeException("System-wide retry budget exhausted. " . $e->getMessage(), 503, $e);
                }

                // Random jitter (0.8x to 1.2x)
                $jitterFactor = mt_rand(800, 1200) / 1000.0;
                $sleepWithJitter = max(1, (int)($sleepMs * $jitterFactor));
                usleep($sleepWithJitter * 1000);
            }
        }
        return null;
    }

    /**
     * اجرای امن عملیات در قالب یک Database Transaction با مدیریت خودکار
     * یکپارچه شده با TransactionWrapper هسته جهت پشتیبانی از Retry و Deadlock Recovery
     */
    protected function transaction(callable $callback, int $maxRetries = 3): mixed
    {
        $db = app(\Core\Database::class);
        // استفاده از کلاس کمکی هسته از طریق کانتینر
        // اگر کانتینر کانفیگ نشده باشد از نمونه‌سازی مستقیم استفاده می‌کنیم
        $wrapper = app(\Core\TransactionWrapper::class);
        if (!$wrapper instanceof \Core\TransactionWrapper) {
            $wrapper = new \Core\TransactionWrapper($db);
        }

        return $wrapper->runWithRetry($callback, $maxRetries);
    }

    /**
     * پاک‌سازی امن کش با قابلیت Retry خودکار برای جلوگیری از Inconsistency
     */
    protected function invalidateCache(string|array $keys, ?callable $fallback = null, int $maxAttempts = 3): void
    {
        $keys = (array)$keys;
        $this->withRetry(function () use ($keys, $fallback) {
            $cache = app(\Core\Cache::class);
            if ($fallback) {
                $fallback($cache);
            } else {
                foreach ($keys as $key) {
                    $cache->forget($key);
                }
            }
        }, $maxAttempts, 100);
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
        if (!$this->idempotencyKey) {
            $this->logger->warning('idempotency.unavailable_no_injection', [
                'scope' => $scope,
                'actor_id' => $actorId,
            ]);
            return $callback();
        }

        if ($explicitKey !== null && $explicitKey !== '') {
            $key = $explicitKey;
        } else {
            $key = $this->idempotencyKey->keyFromPayload($scope, $payload);
        }

        return $this->idempotencyKey->run($scope, $actorId, $key, $callback, $payload);
    }
}