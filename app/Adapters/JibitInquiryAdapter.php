<?php

declare(strict_types=1);

namespace App\Adapters;

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

    public function __construct(LoggerInterface $logger, \Core\Cache $cache)
    {
        $this->logger = $logger;
        $this->cache  = $cache;
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
        
        try {
            // ۱. دریافت Token
            $token = $this->getAccessToken();
            if (!$token) {
                return ['success' => false, 'message' => 'خطا در احراز هویت با سرویس بانکی.'];
            }

            // ۲. درخواست استعلام شبا
            // بر اساس داکیومنت جی‌بیت: GET /v1/services/iban?value=IR...
            $response = $this->makeRequest('GET', 'services/iban?value=' . $iban, [], $token);

            if (isset($response['name'])) {
                return [
                    'success' => true,
                    'owner_name' => $response['name'] . ' ' . ($response['familyName'] ?? ''),
                    'bank' => $response['bank'] ?? null,
                    'message' => 'استعلام با موفقیت انجام شد.'
                ];
            }

            return [
                'success' => false,
                'message' => $response['error']['message'] ?? 'پاسخ نامعتبر از سمت سرویس بانکی.'
            ];

        } catch (\Throwable $e) {
            $this->logger->error('jibit.inquiry.failed', [
                'iban' => $iban,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'عدم برقراری ارتباط با سرویس استعلام شبا.'
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
     * اجرای درخواست خام با CURL
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

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        return json_decode($response, true) ?: null;
    }
}


