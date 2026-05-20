<?php

namespace App\Adapters;

use Core\Logger;
use Core\Cache;
use Core\Database;
use App\Contracts\MetricsCollectorInterface;
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
    private Logger    $logger;
    private Cache     $cache;
    private Database  $db;
    private MetricsCollectorInterface $metrics;
    private ?string   $projectId;
    private ?string   $serviceAccountPath;

    private const FCM_ENDPOINT     = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const TOKEN_CACHE_KEY  = 'fcm:access_token';
    private const TOKEN_TTL        = 55;   // دقیقه (access token هر ساعت expire می‌شود)
    private const BATCH_SIZE       = 500;  // حداکثر FCM multicast batch

    public function __construct(Logger $logger, Cache $cache, Database $db, MetricsCollectorInterface $metrics)
    {
        $this->logger             = $logger;
        $this->cache              = $cache;
        $this->db                 = $db;
        $this->metrics            = $metrics;
        $this->projectId          = config('services.fcm.project_id');
        $this->serviceAccountPath = config('services.fcm.service_account_json');
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

        if ($this->isFcmCircuitOpen()) {
            $this->logger->warning('fcm.circuit_open_skipped', ['token' => substr($fcmToken, 0, 8) . '...']);
            $this->metrics->increment('fcm.circuit.open_skipped');
            return false;
        }

        $payload = $this->buildPayload($title, $body, $data, $imageUrl, $clickUrl);
        $payload['message']['token'] = $fcmToken;

        $startTime = microtime(true);
        try {
            $success = $this->dispatch($payload);
            $duration = microtime(true) - $startTime;
            $this->metrics->timing('fcm.dispatch.latency', $duration);

            if ($success) {
                $this->recordFcmSuccess();
                $this->metrics->increment('fcm.send.success');
            } else {
                $this->recordFcmFailure();
                $this->metrics->increment('fcm.send.failure');
            }
            return $success;
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            $this->metrics->timing('fcm.dispatch.latency', $duration);
            $this->recordFcmFailure();
            $this->metrics->increment('fcm.send.error');
            throw $e;
        }
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
     * ذخیره FCM token برای یک کاربر در کش و پایگاه داده فیزیکی
     */
    public function saveUserToken(int $userId, string $token, string $platform = 'web'): bool
    {
        try {
            // ۱. ذخیره فیزیکی در دیتابیس برای جستجوهای بچ (Batch) و سراسری (پایه ریزی دیسپچر)
            // بررسی وجود رکورد قبلی برای همان پلتفرم
            $exists = $this->db->fetchColumn("SELECT id FROM user_devices WHERE user_id = ? AND platform = ? LIMIT 1", [$userId, $platform]);
            
            if ($exists) {
                $this->db->query("UPDATE user_devices SET fcm_token = ?, last_activity = NOW(), updated_at = NOW() WHERE id = ?", [$token, $exists]);
            } else {
                $this->db->query(
                    "INSERT INTO user_devices (user_id, fcm_token, platform, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())", 
                    [$userId, $token, $platform]
                );
            }

            // ۲. کش کردن برای سرعت بالاتر در پیام‌های تکی آنی (Realtime)
            $key = "fcm_token:user:{$userId}:{$platform}";
            $this->cache->put($key, $token, 60 * 24 * 30); // ۳۰ روز اعتبار کش

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('fcm.save_token_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * دریافت FCM token کاربر (ابتدا از کش و سپس دیتابیس به عنوان پشتیبان)
     */
    public function getUserToken(int $userId, string $platform = 'web'): ?string
    {
        $key = "fcm_token:user:{$userId}:{$platform}";
        $cached = $this->cache->get($key);
        if ($cached) return $cached;

        // Fallback to DB
        $dbToken = $this->db->fetchColumn("SELECT fcm_token FROM user_devices WHERE user_id = ? AND platform = ? LIMIT 1", [$userId, $platform]);
        if ($dbToken) {
            $this->cache->put($key, $dbToken, 60 * 24 * 30);
            return $dbToken;
        }

        return null;
    }

    /**
     * حذف FCM token (logout / token invalid) از تمام لایه‌ها
     */
    public function removeUserToken(int $userId, string $platform = 'web'): void
    {
        try {
            $this->db->query("DELETE FROM user_devices WHERE user_id = ? AND platform = ?", [$userId, $platform]);
            $this->cache->forget("fcm_token:user:{$userId}:{$platform}");
        } catch (\Throwable $e) {
            $this->logger->error('fcm.remove_token_failed', ['error' => $e->getMessage()]);
        }
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

            $header  = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $payload = $this->base64UrlEncode(json_encode([
                'iss'   => $serviceAccount['client_email'],
                'scope' => $scope,
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $expiry,
            ]));

            $signingInput = "{$header}.{$payload}";
            openssl_sign($signingInput, $signature, $serviceAccount['private_key'], 'SHA256');
            $jwt = "{$signingInput}." . $this->base64UrlEncode($signature);

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

    /**
     * رمزگذاری Base64 URL-safe بدون padding (الزامی برای JWT)
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function isFcmCircuitOpen(): bool
    {
        $state = $this->cache->get('fcm:circuit_state') ?: 'closed';
        if ($state === 'open') {
            $lastChange = (int)$this->cache->get('fcm:last_state_change');
            if (time() - $lastChange > 60) {
                // Cool down duration passed, move to half-open state
                $this->cache->put('fcm:circuit_state', 'half-open', 3600);
                $this->cache->put('fcm:last_state_change', time(), 3600);
                $this->logger->info('fcm.circuit_breaker.half_open');
                return false;
            }
            return true;
        }
        return false;
    }

    private function recordFcmSuccess(): void
    {
        $state = $this->cache->get('fcm:circuit_state') ?: 'closed';
        if ($state === 'half-open') {
            $this->cache->put('fcm:circuit_state', 'closed', 3600);
            $this->cache->put('fcm:failures', 0, 3600);
            $this->cache->put('fcm:last_state_change', time(), 3600);
            $this->logger->info('fcm.circuit_breaker.closed');
        } else {
            $this->cache->put('fcm:failures', 0, 3600);
        }
    }

    private function recordFcmFailure(): void
    {
        $state = $this->cache->get('fcm:circuit_state') ?: 'closed';
        if ($state === 'half-open') {
            $this->cache->put('fcm:circuit_state', 'open', 3600);
            $this->cache->put('fcm:last_state_change', time(), 3600);
            $this->logger->warning('fcm.circuit_breaker.opened_from_half_open');
        } else {
            $failures = (int)$this->cache->get('fcm:failures') + 1;
            $this->cache->put('fcm:failures', $failures, 3600);
            if ($failures >= 5) {
                $this->cache->put('fcm:circuit_state', 'open', 3600);
                $this->cache->put('fcm:last_state_change', time(), 3600);
                $this->logger->error('fcm.circuit_breaker.opened', ['consecutive_failures' => $failures]);
            }
        }
    }
}


