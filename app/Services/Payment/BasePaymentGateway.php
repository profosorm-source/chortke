<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use Core\RetryPolicy;

/**
 * BasePaymentGateway - درگاه پیمنٹ بنیادی
 * 
 * تمام payment gateways کے لیے مشترک functionality:
 * - Retry logic with exponential backoff
 * - SSL/TLS verification
 * - Timeout handling
 * - Error logging
 */
abstract class BasePaymentGateway implements PaymentGatewayInterface
{
    /**
     * Exceptions جن کے لیے retry کریں
     * 
     * @var array
     */
    protected array $retryableExceptions = [
        PaymentGatewayConnectionException::class,
        \RuntimeException::class,
    ];

    /**
     * Exceptions جن کے لیے retry نہ کریں (whitelist)
     * 
     * @var array
     */
    protected array $nonRetryableExceptions = [
        'InvalidArgumentException',      // Input validation
        'PaymentVerificationException',   // Invalid transaction
    ];

    /**
     * HTTP status codes جن کے لیے retry کریں
     * 
     * @var array
     */
    protected array $retryableStatusCodes = [
        408,  // Request Timeout
        429,  // Too Many Requests
        500,  // Internal Server Error
        502,  // Bad Gateway
        503,  // Service Unavailable
        504,  // Gateway Timeout
    ];

    protected RetryPolicy $retryPolicy;
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->retryPolicy = new RetryPolicy();
    }

    /**
     * CURL request کو retry کے ساتھ چلائیں
     * 
     * @param string $url درگاہ کا URL
     * @param array $data بھیجنے کا ڈیٹا
     * @param string $method HTTP method (POST, GET, etc.)
     * @param array $headers اضافی headers
     * @return array Response
     * @throws PaymentGatewayConnectionException
     */
    protected function executeWithRetry(
        string $url,
        array $data = [],
        string $method = 'POST',
        array $headers = []
    ): array {
        try {
            return $this->retryPolicy->execute(
                fn() => $this->makeCurlRequest($url, $data, $method, $headers),
                $this->retryableExceptions
            );
        } catch (\Exception $e) {
            $this->logger->error("payment.{$this->getGatewayName()}.request_failed", [
                'error' => $e->getMessage(),
                'url' => $url,
                'attempt' => 'exhausted'
            ]);
            throw new PaymentGatewayConnectionException(
                "Failed to connect to {$this->getGatewayName()} after retries: " . $e->getMessage(),
                $this->getGatewayName()
            );
        }
    }

    /**
     * CURL request کو execute کریں
     * 
     * @param string $url
     * @param array $data
     * @param string $method
     * @param array $headers
     * @return array
     * @throws \Exception
     */
    private function makeCurlRequest(
        string $url,
        array $data,
        string $method,
        array $headers
    ): array {
        $ch = \curl_init($url);

        try {
            // 🔒 SSL/TLS Security Options
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // ⏱️ Timeout Options
            \curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            // 📝 Request Options
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            if (strtoupper($method) === 'POST') {
                \curl_setopt($ch, CURLOPT_POST, true);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            } elseif (strtoupper($method) !== 'GET') {
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }

            // 📨 Headers
            $defaultHeaders = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: ChortkePaymentClient/1.0'
            ];
            $allHeaders = array_merge($defaultHeaders, $headers);
            \curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);

            // Execute request
            $response = \curl_exec($ch);
            $httpCode = (int)\curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = \curl_error($ch);

            \curl_close($ch);

            // Check for CURL errors (connection failures, timeouts, etc.)
            if ($curlError) {
                $this->logRetryAttempt($curlError, $httpCode);
                throw new \RuntimeException("CURL Error: {$curlError}");
            }

            // Check for server errors (should retry)
            if (in_array($httpCode, $this->retryableStatusCodes)) {
                $this->logRetryAttempt("HTTP {$httpCode} - Retrying", $httpCode);
                throw new \RuntimeException("HTTP {$httpCode} - Retryable Server Error");
            }

            // Check for client errors (should not retry)
            if ($httpCode >= 400 && $httpCode < 500 && !in_array($httpCode, $this->retryableStatusCodes)) {
                $this->logger->warning("payment.{$this->getGatewayName()}.client_error", [
                    'http_code' => $httpCode,
                    'response' => $response
                ]);
                throw new \Exception("HTTP {$httpCode} - Client Error (will not retry)");
            }

            // Parse JSON response
            $result = \json_decode($response ?? '{}', true);

            return [
                'success' => $httpCode >= 200 && $httpCode < 300,
                'http_code' => $httpCode,
                'data' => $result,
                'raw_response' => $response
            ];

        } catch (\Exception $e) {
            if (is_resource($ch)) {
                \curl_close($ch);
            }
            throw $e;
        }
    }

    /**
     * Retry attempt کو log کریں
     * 
     * @param string $reason
     * @param int $httpCode
     */
    private function logRetryAttempt(string $reason, int $httpCode): void
    {
        $this->logger->debug("payment.{$this->getGatewayName()}.retry_attempt", [
            'reason' => $reason,
            'http_code' => $httpCode
        ]);
    }

    /**
     * درگاہ کا نام حاصل کریں
     */
    abstract public function getGatewayName(): string;
}
