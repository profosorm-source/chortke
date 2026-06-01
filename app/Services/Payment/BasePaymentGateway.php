<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\LoggerInterface;
use App\Exceptions\PaymentGatewayConnectionException;
use Core\RetryPolicy;
use Core\CircuitBreaker;

/**
 * BasePaymentGateway - درگاه پیمنٹ بنیادی
 * 
 * تمام payment gateways کے لیے مشترک functionality:
 * - Circuit Breaker protection against cascading failures
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


    /**
     * @internal exposed for ExternalCallTrait::resolveCircuitBreaker()
     */
    protected CircuitBreaker $circuitBreaker;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        CircuitBreaker $circuitBreaker
    )
    {        $this->logger = $logger;

        
        $this->retryPolicy = new RetryPolicy();
        $this->circuitBreaker = $circuitBreaker;
    }

    /**
     * Retrieve the gateway configuration object from the concrete gateway implementation.
     */
    protected abstract function getGatewayConfig(): ?object;

    /**
     * Retrieve an optional gateway callback secret for HMAC signature verification.
     */
    protected function getCallbackSecret(): ?string
    {
        $config = $this->getGatewayConfig();
        if ($config !== null) {
            if (isset($config->callback_secret) && $config->callback_secret !== '') {
                return (string)$config->callback_secret;
            }
            if (isset($config->config) && is_string($config->config)) {
                $parsed = json_decode($config->config, true);
                if (is_array($parsed) && !empty($parsed['callback_secret'])) {
                    return (string)$parsed['callback_secret'];
                }
            }
        }

        return config('payment.' . $this->getGatewayName() . '.callback_secret') ?: null;
    }

    /**
     * Verify a generic callback signature using HMAC-SHA256.
     */
    protected function verifyCallbackSignature(array $callbackData, string $secret): bool
    {
        $signature = (string)($callbackData['signature'] ?? $callbackData['sign'] ?? '');
        if ($signature === '') {
            return false;
        }

        $payload = [];
        if (isset($callbackData['Authority'])) {
            $payload[] = $callbackData['Authority'];
        } elseif (isset($callbackData['authority'])) {
            $payload[] = $callbackData['authority'];
        }

        if (isset($callbackData['Status'])) {
            $payload[] = $callbackData['Status'];
        } elseif (isset($callbackData['status'])) {
            $payload[] = $callbackData['status'];
        }

        if (isset($callbackData['amount'])) {
            $payload[] = (string)$callbackData['amount'];
        } elseif (isset($callbackData['Amount'])) {
            $payload[] = (string)$callbackData['Amount'];
        }

        $data = implode('|', $payload);
        return hash_equals(hash_hmac('sha256', $data, $secret), $signature);
    }

    public function verifyCallback(array $callbackData): bool
    {
        $secret = $this->getCallbackSecret();
        $isProduction = (env('APP_ENV') === 'production');
        
        // 🛡️ در محیط تولید، وجود و قدرت کلید الزامی و حیاتی است
        if ($isProduction) {
            if ($secret === null || $secret === '') {
                $this->logger->critical("payment.{$this->getGatewayName()}.callback_secret_missing_production", [
                    'gateway' => $this->getGatewayName(),
                    'message' => 'Callback secret MUST be configured in production!'
                ]);
                throw new \RuntimeException("Callback secret is REQUIRED in production for gateway: {$this->getGatewayName()}");
            }
            
            // 🔒 بررسی طول کلید برای جلوگیری از حملات Brute-force امضا (حداقل ۳۲ کاراکتر)
            if (strlen($secret) < 32) {
                $this->logger->critical("payment.{$this->getGatewayName()}.callback_secret_weak", [
                    'gateway' => $this->getGatewayName(),
                    'length' => strlen($secret)
                ]);
                throw new \RuntimeException("Callback secret is too weak (must be at least 32 characters) for gateway: {$this->getGatewayName()}");
            }
        }
        
        // 🚧 در محیط توسعه (Local/Dev) مجاز به بای‌پس با لاگ هشدار هستیم
        if ($secret === null || $secret === '') {
            $this->logger->warning("payment.{$this->getGatewayName()}.callback_secret_bypass_dev", [
                'gateway' => $this->getGatewayName(),
                'env' => env('APP_ENV', 'local')
            ]);
            return true;
        }
        
        return $this->verifyCallbackSignature($callbackData, $secret);
    }

    /**
     * CURL request کو retry کے ساتھ چلائیں
     * 
     * @param string $url درگاہ کا URL
     * @param array $data بھیجنے کا ڈیٹا
     * @param string $method HTTP method (POST, GET, etc.)
     * @param array $headers اضافی headers
     * @param string $contentType 'json' or 'form'
     * @return array Response
     * @throws PaymentGatewayConnectionException
     */
    protected function executeWithRetry(
        string $url,
        array $data = [],
        string $method = 'POST',
        array $headers = [],
        string $contentType = 'json'
    ): array {
        $context = 'service:payment_gateway:' . $this->getGatewayName();
        
        try {
            return $this->retryPolicy->executeWithContext(
                $context,
                fn() => $this->makeCurlRequest($url, $data, $method, $headers, $contentType),
                $this->retryableExceptions
            );
        } catch (\Exception $e) {
            $this->logger->error("payment.{$this->getGatewayName()}.request_failed", [
                'error' => $e->getMessage(),
                'url' => $url,
                'attempt' => 'exhausted',
                'context' => $context
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
     * @param string $contentType 'json' | 'form'
     * @return array
     * @throws \Exception
     */
    private function makeCurlRequest(
        string $url,
        array $data,
        string $method,
        array $headers,
        string $contentType = 'json'
    ): array {
        $ch = \curl_init($url);
        $method = strtoupper($method);

        try {
            // 🔒 SSL/TLS Security Options
            \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            // ⏱️ Optimized Timeout Options (Reduced from 30s to 20s max for UI response preservation)
            \curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);

            // 📝 Request Options
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            // 🚀 Safe Non-GET Body Distribution Handling
            $body = '';
            $mimeType = 'application/json';
            if ($contentType === 'form') {
                $body = \http_build_query($data);
                $mimeType = 'application/x-www-form-urlencoded';
            } else {
                $body = \json_encode($data);
            }

            if ($method === 'POST') {
                \curl_setopt($ch, CURLOPT_POST, true);
                \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            } elseif ($method !== 'GET') {
                \curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($data)) {
                    \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }

            // 📨 Headers
            $defaultHeaders = [
                "Content-Type: {$mimeType}",
                'Accept: application/json',
                'User-Agent: ChortkePaymentClient/1.0'
            ];
            $allHeaders = array_merge($defaultHeaders, trace_headers(), $headers);
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

            // 🔍 Parse & Audit JSON integrity via strict error checks
            $result = \json_decode($response ?? '{}', true);
            if (\json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("payment.{$this->getGatewayName()}.json_parse_error", [
                    'error' => \json_last_error_msg(),
                    'raw' => substr((string)$response, 0, 250)
                ]);
                throw new \RuntimeException("Malformed JSON response from gateway: " . \json_last_error_msg());
            }

            return [
                'success' => $httpCode >= 200 && $httpCode < 300,
                'http_code' => $httpCode,
                'data' => $result,
                'raw_response' => $response
            ];

        } catch (\Exception $e) {
            // 🛡️ H08 Fix: Replace deprecated is_resource() with instanceof CurlHandle for PHP 8.1+ compatibility
            if ($ch instanceof \CurlHandle || (is_object($ch) && get_class($ch) === 'CurlHandle')) {
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

    /**
     * CircuitBreaker کے ساتھ درخواست کو execute کریں
     * 
     * یہ method CircuitBreaker کے ذریعے درخواستوں کو wrap کرتا ہے تاکہ:
     * - مسلسل ناکامیوں سے بچا جا سکے (Cascading Failures)
     * - درگاہ کے بند ہونے کی فوری صورت میں fast fail کریں
     * - سسٹم کو بوجھ سے بچائے
     * 
     * @param string $url درگاہ کا URL
     * @param array $data بھیجنے کا ڈیٹا
     * @param string $method HTTP method
     * @param array $headers اضافی headers
     * @param string $contentType 'json' or 'form'
     * @return array Response
     * @throws PaymentGatewayConnectionException
     */
    protected function executeWithCircuitBreaker(
        string $url,
        array $data = [],
        string $method = 'POST',
        array $headers = [],
        string $contentType = 'json'
    ): array {
        $gatewayName = 'payment_gateway:' . $this->getGatewayName();
        
        return $this->circuitBreaker->call($gatewayName, function() use ($url, $data, $method, $headers, $contentType) {
            return $this->executeWithRetry($url, $data, $method, $headers, $contentType);
        });
    }
}
