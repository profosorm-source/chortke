<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\LoggerInterface;
use Core\CircuitBreaker;

/**
 * DeepFaceKycAdapter
 * پیاده‌سازی برای فراخوانی یک میکروسرویس هوش مصنوعی (مثلاً پایتونی خودمیزبان یا کلود) 
 * جهت بررسی وجود چهره، تشخیص زنده‌بودن (Liveness) و جلوگیری از تقلب در تصاویر ارسالی KYC.
 */
class DeepFaceKycAdapter implements KycFaceVerificationAdapter
{
    private ?string $apiUrl;
    private ?string $apiToken;
    private LoggerInterface $logger;
    private \Core\Database $db;
    private ?CircuitBreaker $circuitBreaker;

    public function __construct(LoggerInterface $logger, \Core\Database $db, ?CircuitBreaker $circuitBreaker = null)
    {
        $this->logger   = $logger;
        $this->db       = $db;
        $this->circuitBreaker = $circuitBreaker;
        // این تنظیمات از فایل .env خوانده می‌شوند.
        $this->apiUrl   = config('services.deepface.api_url');
        $this->apiToken = config('services.deepface.api_token');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiUrl);
    }

    /**
     * بررسی تصویر از طریق ارسال آن به هوش مصنوعی
     */
    public function analyzeImage(string $absoluteFilePath): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'is_valid' => true, // Fallback transparent: assume valid to pass through manual review
                'ai_notes' => 'سرویس هوش مصنوعی پیکربندی نشده است.'
            ];
        }

        if (!file_exists($absoluteFilePath)) {
            return [
                'success' => false,
                'is_valid' => true,
                'ai_notes' => 'فایل برای تحلیل یافت نشد.'
            ];
        }

        try {
            // ساخت بدنه درخواست شامل فایل آپلودی به صورت multipart
            $ch = curl_init();
            $curlClosed = false;

            $cFile = new \CURLFile($absoluteFilePath);
            $postData = [
                'image' => $cFile
            ];

            // Comprehensive timeout configuration for AI processing
            $timeout = (int)config('services.deepface.timeout', 30);  // AI might need longer
            $connectTimeout = max(3, (int)floor($timeout / 4));

            curl_setopt_array($ch, [
                CURLOPT_URL => $this->apiUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,                    // Total timeout for AI analysis
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,      // Connection timeout
                CURLOPT_DNS_CACHE_TIMEOUT => 120,               // Cache DNS
                CURLOPT_SSL_VERIFYPEER => false,                // For local self-hosted testing
                CURLOPT_FAILONERROR => false,                   // Don't fail silently
            ]);

            if ($this->apiToken) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $this->apiToken
                ]);
            }

            $runner = function () use ($ch) {
                $raw = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_errno($ch);
                $curlErrMsg = curl_error($ch);

                // Handle curl-level errors (connection timeouts, DNS failures, etc.)
                if ($curlErr !== 0) {
                    throw new \Core\Exceptions\TransientException(
                        "درخواست AI انجام نشد: {$curlErrMsg} (کد: {$curlErr})"
                    );
                }

                // Handle HTTP-level errors
                if ($code >= 500 || $code === 408 || $code === 504) {
                    throw new \Core\Exceptions\TransientException(
                        "سرویس AI پاسخ نداد (HTTP {$code})"
                    );
                }

                if ($code !== 200 || !$raw) {
                    throw new \Core\Exceptions\TransientException(
                        "Invalid AI service response (HTTP {$code})"
                    );
                }
                return [$raw, $code];
            };

            [$responseRaw, $httpCode] = $this->circuitBreaker
                ? $this->circuitBreaker->call('deepface_kyc', $runner)
                : $runner();
            curl_close($ch);
            $curlClosed = true;

            if ($httpCode !== 200 || !$responseRaw) {
                throw new \Exception("Invalid AI service response (HTTP $httpCode)");
            }

            $response = json_decode($responseRaw, true);

            // مفروضات خروجی میکروسرویس AI:
            // { "verified": true, "confidence": 0.98, "has_face": true, "error_code": 0 }
            
            $isValid = (bool)($response['verified'] ?? false);
            $confidence = (float)($response['confidence'] ?? 0.0);

            $this->logger->info('kyc.ai.analyzed', [
                'file' => basename($absoluteFilePath),
                'is_valid' => $isValid,
                'confidence' => $confidence
            ]);

            return [
                'success' => true,
                'is_valid' => $isValid,
                'confidence' => $confidence,
                'ai_notes' => $response['notes'] ?? 'تحلیل با موفقیت انجام شد.'
            ];

        } catch (\Throwable $e) {
            if (isset($ch, $curlClosed) && !$curlClosed) {
                @curl_close($ch);
            }
            $this->logger->error('kyc.ai.failed', [
                'error' => $e->getMessage(),
                'file' => basename($absoluteFilePath)
            ]);

            // در صورت خطای اتصال به AI، بازگشت به چرخه نرمال دستی (Fallback)
            return [
                'success' => false,
                'is_valid' => false,
                'ai_notes' => 'خطا در تحلیل هوش مصنوعی: ' . $e->getMessage()
            ];
        }
    }
}


