<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * PaymentGatewayException - بنیادی Payment Gateway استثنیٰ
 * 
 * تمام payment gateway کی خرابیوں کے لیے استعمال ہوتا ہے
 * - Gateway errors
 * - Configuration errors
 * - API errors
 */
class PaymentGatewayException extends \Core\Exceptions\ExternalServiceException
{
    protected $code = 500;
    protected $message = 'Payment gateway error occurred';

    public function __construct(
        string $message = '',
        int $code = 500,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message ?: $this->message, $code, $previous);
    }
}
