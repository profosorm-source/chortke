<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * PaymentVerificationException - پیمنٹ کی تصدیق میں خرابی
 * 
 * جب پیمنٹ کی تصدیق ناکام ہو
 * - Invalid transaction ID
 * - Amount mismatch
 * - Signature verification failed
 * - Transaction not found
 */
class PaymentVerificationException extends PaymentGatewayException
{
    protected $code = 402;
    protected $message = 'Payment verification failed';

    private ?string $transactionId = null;
    private ?array $details = null;

    public function __construct(
        string $message = '',
        ?string $transactionId = null,
        ?array $details = null,
        ?\Throwable $previous = null
    ) {
        $fullMessage = $message ?: $this->message;
        
        if ($transactionId) {
            $fullMessage .= " [Transaction: {$transactionId}]";
        }
        
        parent::__construct($fullMessage, $this->code, $previous);
        
        $this->transactionId = $transactionId;
        if ($details) {
            $this->details = $details;
        }
    }

    /**
     * Transaction ID حاصل کریں
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /**
     * تصدیق کی تفصیلات (مثال: amount mismatch وغیرہ)
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }
}
