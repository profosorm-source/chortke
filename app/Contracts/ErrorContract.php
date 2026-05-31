<?php

namespace App\Contracts;

/**
 * ErrorContract - استاندارد یکنواخت برای پاسخ‌های خطا در سراسر API و Web
 * 
 * این contract تمام انواع خطا (Validation, Authorization, Server, etc.) را normalize می‌کند.
 */
class ErrorContract
{
    // HTTP Status Codes
    public const STATUS_BAD_REQUEST = 400;
    public const STATUS_UNAUTHORIZED = 401;
    public const STATUS_FORBIDDEN = 403;
    public const STATUS_NOT_FOUND = 404;
    public const STATUS_CONFLICT = 409;
    public const STATUS_UNPROCESSABLE = 422;
    public const STATUS_RATE_LIMITED = 429;
    public const STATUS_INTERNAL_ERROR = 500;

    // Error Codes (Business)
    public const CODE_VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const CODE_UNAUTHORIZED = 'UNAUTHORIZED';
    public const CODE_FORBIDDEN = 'FORBIDDEN';
    public const CODE_NOT_FOUND = 'NOT_FOUND';
    public const CODE_CONFLICT = 'CONFLICT';
    public const CODE_RATE_LIMITED = 'RATE_LIMITED';
    public const CODE_INTERNAL_ERROR = 'INTERNAL_ERROR';
    
    // Business-specific error codes
    public const CODE_PAYMENT_FAILED = 'PAYMENT_FAILED';
    public const CODE_INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
    public const CODE_FRAUD_DETECTED = 'FRAUD_DETECTED';
    public const CODE_CONTENT_REJECTED = 'CONTENT_REJECTED';
    public const CODE_DUPLICATE_SUBMISSION = 'DUPLICATE_SUBMISSION';
    public const CODE_INVALID_PLATFORM = 'INVALID_PLATFORM';
    public const CODE_WALLET_ERROR = 'WALLET_ERROR';
    public const CODE_ESCROW_FAILED = 'ESCROW_FAILED';
    public const CODE_BACKUP_FAILED = 'BACKUP_FAILED';
    public const CODE_AUDIT_FAILED = 'AUDIT_FAILED';

    private int $statusCode;
    private string $errorCode;
    private string $message;
    private ?array $details;
    private ?array $fieldErrors;

    /**
     * Static factory for validation errors
     */
    public static function validation(string $message, array $fieldErrors = []): self
    {
        $error = new self(
            self::STATUS_UNPROCESSABLE,
            self::CODE_VALIDATION_ERROR,
            $message ?: 'Validation failed'
        );
        $error->fieldErrors = $fieldErrors;
        return $error;
    }

    /**
     * Static factory for unauthorized
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(
            self::STATUS_UNAUTHORIZED,
            self::CODE_UNAUTHORIZED,
            $message
        );
    }

    /**
     * Static factory for forbidden
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(
            self::STATUS_FORBIDDEN,
            self::CODE_FORBIDDEN,
            $message
        );
    }

    /**
     * Static factory for not found
     */
    public static function notFound(string $message = 'Not found'): self
    {
        return new self(
            self::STATUS_NOT_FOUND,
            self::CODE_NOT_FOUND,
            $message
        );
    }

    /**
     * Static factory for conflict
     */
    public static function conflict(string $message = 'Conflict'): self
    {
        return new self(
            self::STATUS_CONFLICT,
            self::CODE_CONFLICT,
            $message
        );
    }

    /**
     * Static factory for rate limited
     */
    public static function rateLimited(string $message = 'Rate limit exceeded'): self
    {
        return new self(
            self::STATUS_RATE_LIMITED,
            self::CODE_RATE_LIMITED,
            $message
        );
    }

    /**
     * Static factory for internal error
     */
    public static function internalError(string $message = 'Internal server error'): self
    {
        return new self(
            self::STATUS_INTERNAL_ERROR,
            self::CODE_INTERNAL_ERROR,
            $message
        );
    }

    /**
     * Static factory for payment failed
     */
    public static function paymentFailed(string $message = 'Payment failed'): self
    {
        return new self(
            self::STATUS_BAD_REQUEST,
            self::CODE_PAYMENT_FAILED,
            $message
        );
    }

    /**
     * Static factory for insufficient funds
     */
    public static function insufficientFunds(string $message = 'Insufficient funds'): self
    {
        return new self(
            self::STATUS_BAD_REQUEST,
            self::CODE_INSUFFICIENT_FUNDS,
            $message
        );
    }

    /**
     * Static factory for fraud detected
     */
    public static function fraudDetected(string $message = 'Fraud detected'): self
    {
        return new self(
            self::STATUS_FORBIDDEN,
            self::CODE_FRAUD_DETECTED,
            $message
        );
    }

    /**
     * Static factory for content rejected
     */
    public static function contentRejected(string $message = 'Content rejected'): self
    {
        return new self(
            self::STATUS_BAD_REQUEST,
            self::CODE_CONTENT_REJECTED,
            $message
        );
    }

    /**
     * Static factory for duplicate submission
     */
    public static function duplicateSubmission(string $message = 'Duplicate submission'): self
    {
        return new self(
            self::STATUS_CONFLICT,
            self::CODE_DUPLICATE_SUBMISSION,
            $message
        );
    }

    /**
     * Static factory for invalid platform
     */
    public static function invalidPlatform(string $message = 'Invalid platform'): self
    {
        return new self(
            self::STATUS_BAD_REQUEST,
            self::CODE_INVALID_PLATFORM,
            $message
        );
    }

    /**
     * Static factory for wallet error
     */
    public static function walletError(string $message = 'Wallet error'): self
    {
        return new self(
            self::STATUS_BAD_REQUEST,
            self::CODE_WALLET_ERROR,
            $message
        );
    }

    /**
     * Static factory for escrow failed
     */
    public static function escrowFailed(string $message = 'Escrow failed'): self
    {
        return new self(
            self::STATUS_BAD_REQUEST,
            self::CODE_ESCROW_FAILED,
            $message
        );
    }


    /**
     * Static factory for backup failed
     */
    public static function backupFailed(string $message = 'Backup failed'): self
    {
        return new self(
            self::STATUS_INTERNAL_ERROR,
            self::CODE_BACKUP_FAILED,
            $message
        );
    }

    /**
     * Static factory for audit failed
     */
    public static function auditFailed(string $message = 'Audit failed'): self
    {
        return new self(
            self::STATUS_INTERNAL_ERROR,
            self::CODE_AUDIT_FAILED,
            $message
        );
    }

    public function __construct(int $statusCode, string $errorCode, string $message)
    {
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->message = $message;
        $this->details = null;
        $this->fieldErrors = null;
    }

    public function withDetails(array $details): self
    {
        $this->details = $details;
        return $this;
    }

    /**
     * تبدیل به array برای JSON response
     */
    public function toArray(): array
    {
        $response = [
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->message,
            ],
        ];

        if ($this->fieldErrors) {
            $response['error']['field_errors'] = $this->fieldErrors;
        }

        if ($this->details) {
            $response['error']['details'] = $this->details;
        }

        // Add standardized meta block
        $response['meta'] = [
            'trace_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['REQUEST_ID'] ?? uniqid('req-'),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z')
        ];

        return $response;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}
