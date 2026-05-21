<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\CircuitBreakerInterface;
use App\Contracts\LoggerInterface;

/**
 * JibitInquiryAdapter
 * پیاده‌سازی آداپتر استعلام اطلاعات بانکی از طریق سرویس جی‌بیت
 */
class JibitInquiryAdapter implements BankInquiryAdapter
{
    private ?string $apiKey;
    private ?string $apiSecret;
    private string $baseUrl = 'https://api.jibit.ir/v1/';
    private LoggerInterface $logger;
    private \Core\Cache $cache;
    private CircuitBreakerInterface $circuitBreaker;

    public function __construct(LoggerInterface $logger, \Core\Cache $cache, CircuitBreakerInterface $circuitBreaker)
    {
        $this->logger = $logger;
        $this->cache  = $cache;
        $this->circuitBreaker = $circuitBreaker;
        // دریافت متغیرهای اتصال از .env
        $this->apiKey = config('services.jibit.api_key');
        $this->apiSecret = config('services.jibit.api_secret');
    }

    /**
     * بررسی می‌کند که آیا کلیدهای اتصال تنظیم شده‌اند یا خیر
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->apiSecret);
    }

    /**
     * استعلام نام صاحب شبا
     */
    public function inquireIban(string $iban): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'پیکربندی Jibit انجام نشده است.'
            ];
        }

        $iban = strtoupper(trim($iban));
        $cacheKey = 'iban_inquiry:' . hash('sha256', $iban);

        // 1. Check cache first
        try {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $decoded = is_string($cached) ? json_decode($cached, true) : $cached;
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable $ignore) {}
        
        try {
            // 2. Execute within Circuit Breaker and retry with backoff
            $result = $this->circuitBreaker->call('jibit', function() use ($iban) {
                return $this->retryWithBackoff(function() use ($iban) {
                    // ۱. دریافت Token
                    $token = $this->getAccessToken();
                    if (!$token) {
                        throw new \RuntimeException('خطا در احراز هویت با سرویس بانکی.');
                    }

                    // ۲. درخواست استعلام شبا
                    $response = $this->makeRequest('GET', 'services/iban?value=' . $iban, [], $token);

                    if (isset($response['name'])) {
                        return [
                            'success' => true,
                            'owner_name' => $response['name'] . ' ' . ($response['familyName'] ?? ''),
                            'bank' => $response['bank'] ?? null,
                            'message' => 'استعلام با موفقیت انجام شد.'
                        ];
                    }

                    $errorMessage = $response['error']['message'] ?? 'پاسخ نامعتبر از سمت سرویس بانکی.';
                    throw new \RuntimeException($errorMessage);
                }, 3, 500);
            });

            // 3. Cache successful results for 24 hours (1440 minutes)
            if (!empty($result['success'])) {
                try {
                    $this->cache->put($cacheKey, $result, 1440);
                } catch (\Throwable $ignore) {}
            }

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error('jibit.inquiry.failed', [
                'iban' => $iban,
                'error' => $e->getMessage()
            ]);

            if (strpos($e->getMessage(), 'Circuit breaker') !== false) {
                return [
                    'success' => false,
                    'message' => 'سرویس استعلام موقتاً در دسترس نیست. لطفا بعدا تلاش کنید.'
                ];
            }

            return [
                'success' => false,
                'message' => 'عدم برقراری ارتباط با سرویس استعلام شبا: ' . $e->getMessage()
            ];
        }
    }

    /**
     * تولید Access Token جی‌بیت
     */
    private function getAccessToken(): ?string
    {
        $payload = [
            'apiKey' => $this->apiKey,
            'secretKey' => $this->apiSecret,
        ];

        $result = $this->makeRequest('POST', 'tokens/generate', $payload);
        return $result['accessToken'] ?? null;
    }

    /**
     * اجرای درخواست خام با CURL - with comprehensive timeout and error handling
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], ?string $token = null): ?array
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        // Comprehensive timeout handling to prevent hanging requests
        $timeout = (int)config('services.jibit.timeout', 10);
        $connectTimeout = max(2, (int)floor($timeout / 3));

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,                    // Total timeout
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,      // Connection timeout
            CURLOPT_DNS_CACHE_TIMEOUT => 120,               // Cache DNS for 2 minutes
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => false,                   // Don't fail silently on HTTP errors
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch);
        $curlErrMsg = curl_error($ch);
        curl_close($ch);

        // Handle curl errors (connection timeouts, etc.)
        if ($curlErr !== 0) {
            $this->logger->warning('jibit.request.curl_error', [
                'endpoint' => $endpoint,
                'error_code' => $curlErr,
                'error_msg' => $curlErrMsg,
                'timeout' => $timeout,
            ]);
            throw new \RuntimeException("درخواست بانکی انجام نشد: {$curlErrMsg} (کد: {$curlErr})");
        }

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            return json_decode($response, true);
        }

        if ($httpCode >= 500) {
            throw new \RuntimeException("سرویس بانکی در دسترس نیست (HTTP {$httpCode})");
        }

        if ($httpCode === 408 || $httpCode === 504) {
            throw new \RuntimeException("مهلت اتصال به سرویس بانکی تمام شد (HTTP {$httpCode})");
        }

        return json_decode($response, true) ?: null;
    }

    /**
     * Retry a callable with exponential backoff
     */
    private function retryWithBackoff(callable $operation, int $maxAttempts = 3, int $initialDelayMs = 500)
    {
        $attempts = 0;
        while (true) {
            try {
                $attempts++;
                return $operation();
            } catch (\Throwable $e) {
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
                $delay = $initialDelayMs * pow(2, $attempts - 1);
                usleep($delay * 1000);
            }
        }
    }
}


