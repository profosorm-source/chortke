<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

class AppException extends RuntimeException
{
}

class ValidationException extends AppException
{
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed', int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class NotFoundException extends AppException
{
    public function __construct(string $message = 'Not found', int $code = 404)
    {
        parent::__construct($message, $code);
    }
}

class UnauthorizedException extends AppException
{
    public function __construct(string $message = 'Unauthorized', int $code = 401)
    {
        parent::__construct($message, $code);
    }
}

class BusinessException extends AppException
{
}
