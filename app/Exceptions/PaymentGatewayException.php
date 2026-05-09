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
class PaymentGatewayException extends \Exception
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

    /**
     * Error response تیار کریں
     */
    public function getErrorResponse(): array
    {
        return [
            'success' => false,
            'error' => $this->message,
            'error_code' => $this->code,
            'type' => 'payment_gateway_error',
        ];
    }
}
