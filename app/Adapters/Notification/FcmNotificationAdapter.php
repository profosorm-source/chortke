<?php

namespace App\Adapters\Notification;

use Core\Logger;
use Core\Cache;
use Core\Database;
use Core\CircuitBreaker;
use App\Contracts\MetricsCollectorInterface;
use App\Traits\ExternalCallTrait;

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
    use ExternalCallTrait;

    private Logger    $logger;
    private Cache     $cache;
    private Database  $db;
    private MetricsCollectorInterface $metrics;
    private CircuitBreaker $circuit;
    private ?string   $projectId;
    private ?string   $serviceAccountPath;

    private const FCM_ENDPOINT     = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';
    private const TOKEN_CACHE_KEY  = 'fcm:access_token';
    private const TOKEN_TTL        = 55;   // دقیقه (access token هر ساعت expire می‌شود)
    private const BATCH_SIZE       = 500;  // حداکثر FCM multicast batch

    public function __construct(
        Logger $logger,
        Cache $cache,
        Database $db,
        MetricsCollectorInterface $metrics,
        CircuitBreaker $circuit
    ) {
        $this->logger             = $logger;
        $this->cache              = $cache;
        $this->db                 = $db;
        $this->metrics            = $metrics;
        $this->circuit            = $circuit;
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

            // Section 8.3/8.4 — exchange JWT → access token (under CircuitBreaker + retry)
            // OAuth با Google نیز یک external call است و باید با همان الگوی FCM dispatch
            // محافظت شود تا یک خرابی OAuth، کل سیستم نوتیفیکیشن را قفل نکند.
            try {
                $response = $this->callWithBreaker('fcm_oauth', function () use ($jwt): string {
                    return $this->retryTransient(function () use ($jwt): string {
                        $ch = curl_init('https://oauth2.googleapis.com/token');
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => http_build_query([
                                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                                'assertion'  => $jwt,
                            ]),
                            CURLOPT_TIMEOUT        => 10,
                            CURLOPT_CONNECTTIMEOUT => 5,
                        ]);
                        $body  = curl_exec($ch);
                        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $errno = (int) curl_errno($ch);
                        curl_close($ch);

                        if ($code === 200 && is_string($body) && $body !== '') {
                            return $body;
                        }
                        throw $this->classifyHttpFailure($code, $errno, (string)$body, ['provider' => 'fcm_oauth']);
                    });
                });
            } catch (\Core\Exceptions\PermanentFailure $e) {
                // اطلاعات اعتباری اشتباه/ساعت سیستم اشتباه → 4xx — retry بی‌فایده است.
                $this->logger->error('fcm.token_exchange_permanent', ['error' => $e->getMessage()]);
                return null;
            } catch (\Throwable $e) {
                // CB-open، یا خطای transient که retry نتوانست برطرف کند.
                $this->logger->error('fcm.token_exchange_failed', [
                    'class' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
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
    /**
     * Section 8.3/8.4 — wraps HTTP call in Core\CircuitBreaker via the trait
     * and classifies failures into the standard hierarchy.
     */
    private function dispatch(array $payload): bool
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return false;
        }

        $url  = sprintf(self::FCM_ENDPOINT, $this->projectId);
        $json = json_encode(array_filter($payload['message'] ?? $payload, fn($v) => $v !== null), JSON_UNESCAPED_UNICODE);
        $body = json_encode(['message' => json_decode($json, true)], JSON_UNESCAPED_UNICODE);

        try {
            return (bool) $this->callWithBreaker('fcm', function () use ($url, $body): bool {
                return $this->retryTransient(function () use ($url, $body): bool {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $body,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer ' . $this->getAccessToken(),
                            'Content-Type: application/json',
                        ],
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_CONNECTTIMEOUT => 5,
                    ]);
                    $response = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno    = (int) curl_errno($ch);
                    $error    = curl_error($ch);
                    curl_close($ch);

                    if ($httpCode === 200) {
                        return true;
                    }
                    if ($httpCode === 401) {
                        // token expired → invalidate cache so the next attempt re-issues
                        $this->cache->forget(self::TOKEN_CACHE_KEY);
                    }
                    $this->logger->warning('fcm.send_failed', [
                        'http'  => $httpCode,
                        'errno' => $errno,
                        'error' => $error ?: (is_string($response) ? mb_substr($response, 0, 200) : ''),
                    ]);
                    throw $this->classifyHttpFailure($httpCode, $errno, (string)$response, ['provider' => 'fcm']);
                });
            }, function (\Core\Exceptions\CircuitBreakerOpenException $e) use ($payload) {
                // Fallback: If circuit is open, queue the FCM push instead of dropping it
                $this->logger->warning('fcm.circuit_open_fallback_to_queue');
                
                try {
                    $queue = \Core\Container::getInstance()->make(\Core\Queue::class);
                    // Push to generic notifications queue
                    $queue->push('App\\Jobs\\SendFcmJob', [
                        'payload' => $payload
                    ], 'notifications', 60); // Delay 60s
                    return true;
                } catch (\Throwable $qe) {
                    $this->logger->error('fcm.queue_fallback_failed', ['error' => $qe->getMessage()]);
                    return false;
                }
            });
        } catch (\Core\Exceptions\PermanentFailure $e) {
            // Permanent 4xx — log + return false (do NOT propagate up to caller as exception)
            $this->logger->warning('fcm.permanent_failure', ['error' => $e->getMessage()]);
            return false;
        } catch (\Throwable $e) {
            // Transient/Provider/RateLimited or CB-open → log + return false (matches old behavior)
            $this->logger->warning('fcm.transient_failure', [
                'class' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * رمزگذاری Base64 URL-safe بدون padding (الزامی برای JWT)
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // -------------------------------------------------------------------------
    // Legacy home-grown circuit breaker — kept as no-ops for binary compatibility.
    // The real CB is Core\CircuitBreaker invoked via ExternalCallTrait::callWithBreaker('fcm').
    // -------------------------------------------------------------------------
    private function isFcmCircuitOpen(): bool { return false; }
    private function recordFcmSuccess(): void {}
    private function recordFcmFailure(): void {}
}
