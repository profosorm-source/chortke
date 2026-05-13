<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * PaymentGatewayConnectionException - درگاه سے رابطہ کی خرابی
 * 
 * جب payment gateway سے رابطہ قائم نہ ہو سکے
 * - Network timeout
 * - Connection refused
 * - SSL verification failed
 */
class PaymentGatewayConnectionException extends PaymentGatewayException
{
    protected $code = 503;
    protected $message = 'Unable to connect to payment gateway';

    public function __construct(string $message = '', ?string $gateway = null, ?\Throwable $previous = null)
    {
        $fullMessage = $gateway 
            ? "Connection failed to gateway [{$gateway}]: {$message}" 
            : "Payment gateway connection error: {$message}";
        
        parent::__construct($fullMessage, $this->code, $previous);
    }
}
