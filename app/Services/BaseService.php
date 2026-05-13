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
            'trace' => array_slice($e->getTrace(), 0, 5),
        ]);
    }

    /**
     * Wrap closures within isolated, safe database transactions.
     */
    protected function transaction(callable $callback): mixed
    {
        $db = db();
        try {
            $db->beginTransaction();
            $result = $callback();
            $db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
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
}
