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

            curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15); // هوش مصنوعی شاید کمی زمان ببرد
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // برای تست لوکال با خودمیزبان

            if ($this->apiToken) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $this->apiToken
                ]);
            }

            $runner = function () use ($ch) {
                $raw = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($code !== 200 || !$raw) {
                    throw new \Core\Exceptions\TransientException("Invalid AI service response (HTTP {$code})");
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


