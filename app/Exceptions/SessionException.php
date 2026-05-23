<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * SessionException - Session سے متعلق خرابی
 * 
 * تمام session management کی خرابیوں کے لیے
 * - Session expired
 * - Invalid session ID
 * - Session hijacking detected
 * - Anomalous behavior detected
 */
class SessionException extends \Core\Exceptions\AppException
{
    protected $code = 419;
    protected $message = 'Session error';

    public const REASON_EXPIRED = 'expired';
    public const REASON_INVALID = 'invalid';
    public const REASON_HIJACKING = 'hijacking_detected';
    public const REASON_ANOMALY = 'anomalous_behavior';
    public const REASON_TIMEOUT = 'timeout';

    private string $reason;

    public function __construct(
        string $message = '',
        string $reason = self::REASON_INVALID,
        int $code = 419,
        ?\Throwable $previous = null
    ) {
        $this->reason = $reason;
        
        $fullMessage = $message ?: match ($reason) {
            self::REASON_EXPIRED => 'Your session has expired',
            self::REASON_INVALID => 'Invalid session ID',
            self::REASON_HIJACKING => 'Suspicious session activity detected',
            self::REASON_ANOMALY => 'Unusual behavior detected in session',
            self::REASON_TIMEOUT => 'Session timeout',
            default => $this->message,
        };
        
        parent::__construct($fullMessage, $code, $previous);
    }

    /**
     * Reason getter
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Session کو terminate کیا جائے یا نہیں
     */
    public function shouldTerminateSession(): bool
    {
        return in_array($this->reason, [
            self::REASON_HIJACKING,
            self::REASON_ANOMALY,
            self::REASON_INVALID,
        ]);
    }
}
