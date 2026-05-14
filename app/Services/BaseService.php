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

    protected ?\Core\Database $db = null; // M26 Fix: به سازنده تزریق نمی‌شود تا تمام subclassها خراب نشوند، اما در تست‌ها قابل تنظیم است

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
            'trace' => array_slice($e->getTrace(), 0, 5),
        ]);
    }

    /**
     * Wrap closures within isolated, safe database transactions.
     */
    protected function transaction(callable $callback): mixed
    {
        $db = $this->db ?? app(\Core\Database::class);
        $started = !$db->inTransaction();
        if ($started) {
            $db->beginTransaction();
        }
        try {
            $result = $callback();
            if ($started) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
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
     * 🚀 UPG-02: اجرای توابع با منطق تلاش مجدد (Retry Logic) جهت مواجهه با خطاهای لحظه‌ای
     */
    protected function withRetry(callable $fn, int $times = 3, int $sleepMs = 100): mixed
    {
        $attempts = 0;
        while ($attempts < $times) {
            try {
                return $fn();
            } catch (\Throwable $e) {
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
}
