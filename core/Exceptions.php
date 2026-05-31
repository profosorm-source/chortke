<?php

declare(strict_types=1);

namespace Core\Exceptions;

use RuntimeException;

class AppException extends RuntimeException
{
}

class PayloadTooLargeException extends AppException
{
    public function __construct(string $message = 'Payload too large', int $code = 413)
    {
        parent::__construct($message, $code);
    }
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

class SecurityException extends AppException
{
    public function __construct(string $message = 'Security validation failed', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}

class BusinessException extends AppException
{
}

class DomainException extends BusinessException
{
}

class ApplicationException extends AppException
{
}

class InfrastructureException extends AppException
{
}

class TransientException extends InfrastructureException
{
}

class ExternalServiceException extends InfrastructureException
{
}

class InsufficientBalanceException extends BusinessException
{
    public function __construct(string $message = 'موجودی حساب کافی نیست', int $code = 400)
    {
        parent::__construct($message, $code);
    }
}

class EntityNotFoundException extends NotFoundException
{
    public function __construct(string $message = 'موجودیت مورد نظر یافت نشد', int $code = 404)
    {
        parent::__construct($message, $code);
    }
}

class RateLimitExceededException extends AppException
{
    public function __construct(string $message = 'تعداد درخواست‌ها بیش از حد مجاز است', int $code = 429)
    {
        parent::__construct($message, $code);
    }
}

class FraudDetectedException extends SecurityException
{
    public function __construct(string $message = 'فعالیت مشکوک شناسایی شد', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}

class InvalidStateException extends BusinessException
{
    public function __construct(string $message = 'وضعیت درخواست نامعتبر است', int $code = 409)
    {
        parent::__construct($message, $code);
    }
}

/**
 * Section 8.4 — Failure classification for external adapters.
 *
 * Retry/CB matrix:
 *   TransientException     -> retry + count toward circuit-breaker failures
 *   RateLimitedFailure     -> back off (don't count toward CB tripping)
 *   ProviderUnavailable    -> retry + STRONGLY count toward CB
 *   PermanentFailure       -> DO NOT retry, DO NOT count toward CB
 *
 * All four extend ExternalServiceException so existing catch sites keep working.
 */
class PermanentFailure extends ExternalServiceException
{
}

class RateLimitedFailure extends ExternalServiceException
{
    private int $retryAfterSeconds;

    public function __construct(
        string $message = 'Provider rate limit exceeded',
        int $retryAfterSeconds = 0,
        int $code = 429
    ) {
        parent::__construct($message, $code);
        $this->retryAfterSeconds = max(0, $retryAfterSeconds);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}

class ProviderUnavailable extends ExternalServiceException
{
}

class HttpResponseException extends AppException
{
    private $response;

    public function __construct($response, string $message = "HTTP Response Terminated", int $code = 0)
    {
        parent::__construct($message, $code);
        $this->response = $response;
    }

    public function getResponse()
    {
        return $this->response;
    }
}

/**
 * Exception thrown when a CircuitBreaker rejects an operation because the circuit is OPEN.
 */
class CircuitBreakerOpenException extends ExternalServiceException
{
    private string $serviceName;

    public function __construct(string $serviceName, string $message = "", int $code = 503, ?\Throwable $previous = null)
    {
        $this->serviceName = $serviceName;
        $message = $message ?: "Circuit breaker for {$serviceName} is OPEN. Request aborted.";
        parent::__construct($message, $code, $previous);
    }

    public function getServiceName(): string
    {
        return $this->serviceName;
    }
}
