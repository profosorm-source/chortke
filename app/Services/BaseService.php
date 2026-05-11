<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Exceptions\ValidationException;

abstract class BaseService
{
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

    protected function logError(string $event, array $context = []): void
    {
        $this->logger->error($event, $context);
    }

    protected function guardValidation(array $validationResult): void
    {
        if (empty($validationResult['valid'])) {
            throw new ValidationException($validationResult['errors']);
        }
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
