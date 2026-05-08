<?php

namespace App\Services\Notification\Adapters;

use Core\Logger;
use Core\Cache;

/**
 * FcmNotificationAdapter — ارسال Push Notification با Firebase Cloud Messaging (FCM)
 *
 * ─── تنظیمات .env مورد نیاز ────────────────────────────────────────────────
 *  FCM_SERVICE_ACCOUNT_JSON=/path/to/storage/firebase-service-account.json
 *  FCM_PROJECT_ID=your-firebase-project-id
 *
 * ─── نحوه استفاده ──────────────────────────────────────────────────────────
 *  $fcm->sendToToken($fcmToken, 'عنوان', 'متن', ['key' => 'val']);
 *  $fcm->sendToTokens([$token1, $token2], 'عنوان', 'متن');
 */
class FcmNotificationAdapter
{
    private Logger  $logger;
    private Cache   $cache;
    private ?string $projectId;
    private ?string $serviceAccountPath;

    private const FCM_ENDPOINT     = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const TOKEN_CACHE_KEY  = 'fcm:access_token';
    private const TOKEN_TTL        = 55;   // دقیقه (access token هر ساعت expire می‌شود)
    private const BATCH_SIZE       = 500;  // حداکثر FCM multicast batch

    public function __construct(Logger $logger, Cache $cache)
    {
        $this->logger             = $logger;
        $this->cache              = $cache;
        $this->projectId          = env('FCM_PROJECT_ID');
        $this->serviceAccountPath = env('FCM_SERVICE_ACCOUNT_JSON');
    }

    /**
     * ارسال به یک token
     */
    public function sendToToken(
        string $fcmToken,
        string $title,
        string $body,
        array  $data      = [],
        ?string $imageUrl = null,
        ?string $clickUrl = null
    ): bool {
        if (!$this->isConfigured()) {
            $this->logger->warning('fcm.not_configured');
            return false;
        }

        $payload = $this->buildPayload($title, $body, $data, $imageUrl, $clickUrl);
        $payload['message']['token'] = $fcmToken;

        return $this->dispatch($payload);
    }

    /**
     * ارسال به چند token (batch)
     */
    public function sendToTokens(
        array  $fcmTokens,
        string $title,
        string $body,
        array  $data      = [],
        ?string $imageUrl = null,
        ?string $clickUrl = null
    ): array {
        if (!$this->isConfigured() || empty($fcmTokens)) {
            return ['sent' => 0, 'failed' => count($fcmTokens)];
        }

        $sent   = 0;
        $failed = 0;

        // FCM V1 API یک token در هر request قبول می‌کند
        // برای batch بهینه، می‌توان از HTTP/2 multiplexing استفاده کرد
        // فعلاً با حلقه (قابل upgrade به parallel با curl_multi)
        foreach (array_chunk($fcmTokens, self::BATCH_SIZE) as $batch) {
            foreach ($batch as $token) {
                if ($this->sendToToken($token, $title, $body, $data, $imageUrl, $clickUrl)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
        }

        $this->logger->info('fcm.batch_sent', ['sent' => $sent, 'failed' => $failed]);
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * ذخیره FCM token برای یک کاربر
     */
    public function saveUserToken(int $userId, string $token, string $platform = 'web'): bool
    {
        // TODO: ذخیره در جدول user_fcm_tokens (در migration بعدی)
        // فعلاً در cache با TTL 30 روز
        $key = "fcm_token:user:{$userId}:{$platform}";
        $this->cache->put($key, $token, 60 * 24 * 30);
        return true;
    }

    /**
     * دریافت FCM token کاربر
     */
    public function getUserToken(int $userId, string $platform = 'web'): ?string
    {
        $key = "fcm_token:user:{$userId}:{$platform}";
        return $this->cache->get($key);
    }

    /**
     * حذف FCM token (logout / token invalid)
     */
    public function removeUserToken(int $userId, string $platform = 'web'): void
    {
        $this->cache->forget("fcm_token:user:{$userId}:{$platform}");
    }

    /**
     * ارسال push به یک کاربر (با lookup token از cache/db)
     */
    public function sendToUser(
        int    $userId,
        string $title,
        string $body,
        array  $data      = [],
        ?string $imageUrl = null,
        ?string $clickUrl = null
    ): bool {
        $token = $this->getUserToken($userId);
        if (!$token) {
            return false; // کاربر token نداشت — ok، نه error
        }

        return $this->sendToToken($token, $title, $body, $data, $imageUrl, $clickUrl);
    }

    /**
     * بررسی آماده بودن FCM
     */
    public function isConfigured(): bool
    {
        return !empty($this->projectId)
            && !empty($this->serviceAccountPath)
            && file_exists($this->serviceAccountPath);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal — Authentication (OAuth2 با Service Account)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * دریافت access token از Google (با cache)
     */
    private function getAccessToken(): ?string
    {
        // بررسی cache
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        try {
            $serviceAccount = json_decode(file_get_contents($this->serviceAccountPath), true);

            $now     = time();
            $expiry  = $now + 3600;
            $scope   = 'https://www.googleapis.com/auth/firebase.messaging';

            $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = base64_encode(json_encode([
                'iss'   => $serviceAccount['client_email'],
                'scope' => $scope,
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $expiry,
            ]));

            $signingInput = "{$header}.{$payload}";
            openssl_sign($signingInput, $signature, $serviceAccount['private_key'], 'SHA256');
            $jwt = "{$signingInput}." . base64_encode($signature);

            // exchange JWT → access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'  => $jwt,
                ]),
                CURLOPT_TIMEOUT        => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                $this->logger->error('fcm.token_exchange_failed', ['http' => $httpCode]);
                return null;
            }

            $data  = json_decode($response, true);
            $token = $data['access_token'] ?? null;

            if ($token) {
                $this->cache->put(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL);
            }

            return $token;

        } catch (\Throwable $e) {
            $this->logger->error('fcm.auth_failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * ساخت payload پیام FCM
     */
    private function buildPayload(
        string  $title,
        string  $body,
        array   $data,
        ?string $imageUrl,
        ?string $clickUrl
    ): array {
        $notification = [
            'title' => $title,
            'body'  => $body,
        ];

        if ($imageUrl) {
            $notification['image'] = $imageUrl;
        }

        $webpush = [];
        if ($clickUrl) {
            $webpush = [
                'fcm_options' => ['link' => $clickUrl],
            ];
        }

        // data باید string-string باشد
        $stringData = array_map('strval', $data);

        return [
            'message' => [
                'notification' => $notification,
                'data'         => $stringData,
                'webpush'      => $webpush ?: null,
                'android'      => [
                    'notification' => [
                        'sound'       => 'default',
                        'click_action'=> 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
                'apns' => [
                    'payload' => [
                        'aps' => ['sound' => 'default'],
                    ],
                ],
            ],
        ];
    }

    /**
     * ارسال واقعی به FCM API
     */
    private function dispatch(array $payload): bool
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return false;
        }

        $url  = sprintf(self::FCM_ENDPOINT, $this->projectId);
        $json = json_encode(array_filter($payload['message'] ?? $payload, fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);

        // wrapping مجدد با ساختار صحیح
        $body = json_encode(['message' => json_decode($json, true)], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200) {
            return true;
        }

        // token منقضی شده — پاک‌کردن cache
        if ($httpCode === 401) {
            $this->cache->forget(self::TOKEN_CACHE_KEY);
        }

        $this->logger->warning('fcm.send_failed', [
            'http'  => $httpCode,
            'error' => $error ?: $response,
        ]);

        return false;
    }
}
