<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\LoggerInterface;
use App\Traits\ExternalCallTrait;
use Core\CircuitBreaker;

/**
 * DeepFaceKycAdapter
 * پیاده‌سازی برای فراخوانی یک میکروسرویس هوش مصنوعی (مثلاً پایتونی خودمیزبان یا کلود)
 * جهت بررسی وجود چهره، تشخیص زنده‌بودن (Liveness) و جلوگیری از تقلب در تصاویر ارسالی KYC.
 *
 * Section 8.3/8.4 — کل HTTP call داخل Core\CircuitBreaker انجام می‌شود و خطاهای
 * transient (timeout, 5xx) با retryTransient() تکرار می‌شوند. خطاهای 4xx
 * permanent محسوب شده و بدون retry پاس می‌شوند.
 */
class DeepFaceKycAdapter implements KycFaceVerificationAdapter
{
    use ExternalCallTrait;

    private ?string $apiUrl;
    private ?string $apiToken;
    private LoggerInterface $logger;
    private \Core\Database $db;

    /**
     * @internal exposed for ExternalCallTrait::resolveCircuitBreaker()
     */
    protected CircuitBreaker $circuitBreaker;

    public function __construct(LoggerInterface $logger, \Core\Database $db, CircuitBreaker $circuitBreaker)
    {
        $this->logger = $logger;
        $this->db = $db;
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

        // Comprehensive timeout configuration for AI processing
        $timeout = (int)config('services.deepface.timeout', 30);  // AI might need longer
        $connectTimeout = max(3, (int)floor($timeout / 4));
        $apiUrl   = $this->apiUrl;
        $apiToken = $this->apiToken;

        try {
            // CB + retry روی فراخوانی AI — یک curl handle جدید در هر retry تا
            // در صورت خطای انتقال داده، state تمیز بماند.
            $responseRaw = $this->callWithBreaker('deepface_kyc', function () use ($apiUrl, $apiToken, $absoluteFilePath, $timeout, $connectTimeout): string {
                return $this->retryTransient(function () use ($apiUrl, $apiToken, $absoluteFilePath, $timeout, $connectTimeout): string {
                    $ch = curl_init();
                    try {
                        $cFile = new \CURLFile($absoluteFilePath);
                        $postData = ['image' => $cFile];

                        curl_setopt_array($ch, [
                            CURLOPT_URL => $apiUrl,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $postData,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => $timeout,                    // Total timeout for AI analysis
                            CURLOPT_CONNECTTIMEOUT => $connectTimeout,      // Connection timeout
                            CURLOPT_DNS_CACHE_TIMEOUT => 120,               // Cache DNS
                            CURLOPT_SSL_VERIFYPEER => false,                // For local self-hosted testing
                            CURLOPT_FAILONERROR => false,                   // Don't fail silently
                        ]);

                        if ($apiToken) {
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Authorization: Bearer ' . $apiToken
                            ]);
                        }

                        $raw  = curl_exec($ch);
                        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $errno = (int) curl_errno($ch);
                    } finally {
                        @curl_close($ch);
                    }

                    if ($code === 200 && is_string($raw) && $raw !== '') {
                        return $raw;
                    }

                    // طبقه‌بندی استاندارد: 4xx → PermanentFailure (no retry)،
                    // 5xx/timeout/network → Transient/Provider (retry)
                    throw $this->classifyHttpFailure($code, $errno, (string)$raw, ['provider' => 'deepface_kyc']);
                }, 3, 500, 4000);
            });

            $response = json_decode($responseRaw, true);

            // مفروضات خروجی میکروسرویس AI:
            // { "verified": true, "confidence": 0.98, "has_face": true, "error_code": 0 }
            $isValid    = (bool)($response['verified'] ?? false);
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

        } catch (\Core\Exceptions\PermanentFailure $e) {
            // 4xx از سرویس AI: درخواست نامعتبر — بازگشت به مسیر دستی
            $this->logger->warning('kyc.ai.permanent_failure', [
                'error' => $e->getMessage(),
                'file' => basename($absoluteFilePath),
            ]);
            return [
                'success' => false,
                'is_valid' => false,
                'ai_notes' => 'پاسخ نامعتبر از سرویس هوش مصنوعی.'
            ];
        } catch (\Throwable $e) {
            // CB-open، یا transient که پس از retry هم برطرف نشد، یا خطای داخلی
            $this->logger->error('kyc.ai.failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => basename($absoluteFilePath)
            ]);

            // در صورت خطای اتصال به AI، بازگشت به چرخه نرمال دستی (Fallback)
            return [
                'success' => false,
                'is_valid' => true, // Fallback to manual review
                'ai_notes' => 'خطا در تحلیل هوش مصنوعی: ' . $e->getMessage()
            ];
        }
    }
}
