<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Services\AntiFraud\RiskPolicyService;
use App\Services\DistributedLockService;
use App\Services\Notification\NotificationService;
use App\Contracts\LoggerInterface;
use Core\Database;
/**
 * SessionService
 *
 * مدیریت نشست‌های کاربری و تحلیل ناهنجاری‌ها.
 * 
 * SECURITY NOTES:
 * - Session cookies are set with httponly, secure, and samesite flags
 * - Session termination notifications sent to users
 * - Concurrent session limits enforced with oldest session removal
 */
class SessionService
{
    public const SUSPICIOUS_UA_CHANGE_SECONDS = 300;
    public const SUSPICIOUS_GEO_CHANGE_SECONDS = 3600;
    public const UNUSUAL_HOUR_START = 2;
    public const UNUSUAL_HOUR_END = 6;
    public const MAX_ACTIONS_PER_MINUTE = 20;

    private \Core\Redis $redis;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    private SecurityModel $model;
    private RiskPolicyService $policy;
    private NotificationService $notificationService;
    private DistributedLockService $lockService;
    public function __construct(
        \Core\Redis $redis,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        SecurityModel $model,
        RiskPolicyService $policy,
        NotificationService $notificationService,
        DistributedLockService $lockService
    ) {        $this->redis = $redis;
        $this->db = $db;
        $this->logger = $logger;
        $this->model = $model;
        $this->policy = $policy;
        $this->notificationService = $notificationService;
        $this->lockService = $lockService;

        
    }

    /**
     * ثبت نشست جدید
     * 
     * @param int $userId شناسه کاربر
     * @param string $sessionId شناسه نشست
     * @param string $userAgent User-Agent Header (از HTTP Layer)
     * @param string $ipAddress آدرس IP کاربر (از HTTP Layer)
     * @param string $acceptLanguage Accept-Language Header
     * @param string $acceptEncoding Accept-Encoding Header
     * @param array|null $geoData داده‌های جغرافیایی
     * 
     * @return bool موفقیت عملیات
     * @throws \InvalidArgumentException اگر ورودی‌ها نادرست باشند
     */
    public function recordSession(
        int $userId,
        string $sessionId,
        string $userAgent,
        string $ipAddress,
        string $acceptLanguage = '',
        string $acceptEncoding = '',
        ?array $geoData = null
    ): bool {
        // Input validation
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user ID: must be positive');
        }

        if (empty($sessionId) || strlen($sessionId) > 255) {
            throw new \InvalidArgumentException('Invalid session ID: must be non-empty and max 255 chars');
        }

        if (empty($userAgent)) {
            throw new \InvalidArgumentException('User-Agent cannot be empty');
        }

        if (empty($ipAddress)) {
            throw new \InvalidArgumentException('IP address cannot be empty');
        }

        if ($geoData !== null) {
            if (!is_array($geoData) || empty($geoData['country'])) {
                throw new \InvalidArgumentException('Invalid geo data: must contain country');
            }
        }

        // 🔒 DISTRIBUTED LOCK: Prevent race conditions across multiple concurrent login attempts for the same user
        $lockResource = "session_limit:{$userId}";
        $lock = $this->lockService->acquire($lockResource, ttl: 30, waitTimeout: 5);

        if (!$lock['acquired']) {
            // MEDIUM-M-14 Fix: Abort on distributed lock failure to prevent race conditions.
            $this->logger->warning('session.record_session.lock_failed', [
                'user_id' => $userId,
                'session_id' => $sessionId
            ]);
            throw new \RuntimeException('Session creation temporarily unavailable due to lock contention.');
        }

        try {
            // ✅ استفاده از parameters، نه $_SERVER
            $deviceInfo = $this->parseUserAgent($userAgent);
            $fingerprint = $this->generateFingerprint($userAgent, $acceptLanguage, $acceptEncoding);

            // 🔒 PESSIMISTIC LOCKING: Prevent race condition in session creation
            $this->db->beginTransaction();

            // Safe Port: Moved RAW serialized lock to native atomic QueryBuilder execution.
            $existing = $this->db->table('user_sessions')
                ->where('session_id', '=', $sessionId)
                ->lockForUpdate()
                ->select('id')
                ->first();

            if ($existing) {
                // Session exists, just update activity timestamp
                $result = $this->model->updateSessionActivity($sessionId);
                $this->db->commit();
                return $result;
            }

            // Session doesn't exist, create it
            
            // LOW-L-05 Fix: Concurrent Session Handling - Enforcement of hard limit with notification
            $activeSessions = $this->model->countActiveSessions($userId);
            $maxSessions = (int)config('auth.max_concurrent_sessions', 5);
            
            if ($activeSessions >= $maxSessions) {
                // MEDIUM-M-03 Fix: Notify user before terminating oldest session
                $oldestSession = $this->model->getOldestActiveSession($userId);
                if ($oldestSession) {
                    $this->notifySessionTermination($userId, $oldestSession);
                }
                
                // Terminate oldest session to make room (LOW-L-02 Fix: Selective invalidation instead of mass)
                $this->model->deactivateOldestSession($userId);
                $this->logger->info('session.limit_reached.auto_cleanup', ['user_id' => $userId]);
            }

            $result = $this->model->upsertSession([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'device_type' => $deviceInfo['device_type'],
                'browser' => $deviceInfo['browser'],
                'os' => $deviceInfo['os'],
                'country' => $geoData['country'] ?? null,
                'city' => $geoData['city'] ?? null,
                'fingerprint' => $fingerprint
            ]);

            $this->db->commit();
            return $result;

        } catch (\Exception $e) {
            // 🛡️ H02 Fix: Safe rollback — check if transaction is active before rolling back
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logger->error('session.record_session.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            // 🔒 Always release distributed lock if it was acquired
            if (!empty($lock['token'])) {
                $this->lockService->release($lockResource, $lock['token']);
            }
        }
    }
    
    /**
     * MEDIUM-M-03 Fix: Notify user about session termination
     * Sends notification to user before their oldest session is terminated
     */
    private function notifySessionTermination(int $userId, object $oldestSession): void
    {
        try {
            // Log the termination for audit
            $this->logger->info('session.termination.notification', [
                'user_id' => $userId,
                'session_id' => $oldestSession->session_id,
                'device' => $oldestSession->device_type ?? 'unknown',
                'browser' => $oldestSession->browser ?? 'unknown',
                'created_at' => $oldestSession->created_at ?? 'unknown'
            ]);
            
            // 🚀 ارسال async به جای notificationService->sendToUser مستقیم
            // تا جلوگیری از بلاک شدن سشن در صورت خطای ارسال اطلاعیه
            \Core\EventDispatcher::getInstance()->dispatchAsync('notification.requested', [
                'user_id' => $userId,
                'type' => 'security',
                'title' => 'پایان نشست قدیمی',
                'message' => 'یک نشست قدیمی از دستگاه "' . ($oldestSession->browser ?? 'نامشخص') . '" روی "' . ($oldestSession->device_type ?? 'دستگاه نامشخص') . '" به پایان رسید.',
                'priority' => 'high'
            ]);
        } catch (\Throwable $e) {
            // Don't fail the session termination if notification fails
            $this->logger->warning('session.notification_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function updateActivity(string $sessionId): bool
    {
        return $this->model->updateSessionActivity($sessionId);
    }

    public function getActiveSessions(int $userId): array
    {
        return $this->model->getActiveSessions($userId);
    }

    public function terminateSession(int $id, int $userId): array
    {
        // CRITICAL-C-03 Fix: Using numeric ID and owner check to prevent IDOR
        $session = $this->model->findSessionById($id);
        if (!$session || (int)$session->user_id !== $userId) {
            return ['success' => false, 'message' => 'نشست یافت نشد'];
        }

        // 🛡️ State Domain Guard: Prevent redundant or logically corrupted state transitions.
        if (empty($session->is_active)) {
            return ['success' => false, 'message' => 'این نشست از قبل غیرفعال شده است'];
        }

        $this->model->deactivateSession((int)$session->id);

        // LOW-06 Fix: Clear Redis activity key
        if ($this->redis->isAvailable()) {
            try { $this->redis->delete("session:activity:" . $session->session_id); } catch (\Throwable) {}
        }

        return ['success' => true, 'message' => 'نشست با موفقیت حذف شد'];
    }

    public function invalidateAllUserSessions(int $userId, ?string $excludeSessionId = null): bool
    {
        $this->logger->info('session.invalidate_all', ['user_id' => $userId, 'exclude' => $excludeSessionId]);
        
        // LOW-06 Fix: Get all active sessions to clear Redis keys
        $sessions = $this->model->getActiveSessions($userId);
        
        $result = $this->model->deactivateUserSessions($userId, $excludeSessionId);
        
        if ($this->redis->isAvailable()) {
            foreach ($sessions as $s) {
                if ($excludeSessionId && $s->session_id === $excludeSessionId) continue;
                try { $this->redis->delete("session:activity:" . $s->session_id); } catch (\Throwable) {}
            }
        }

        return $result;
    }

    public function cleanupSessions(): void
    {
        $this->model->expireOldSessions(7);
        $this->model->deleteInactiveSessions(30);
    }

    public function analyzeAnomaly(int $userId, string $sessionId): array
    {
        $this->logger->info('session.anomaly.analyze.started', ['user_id' => $userId, 'session_id' => $sessionId]);
        
        $anomalies = [];
        $score = 0;

        // Concurrent sessions check
        $threshold = $this->policy->getInt('fraud', 'session.concurrent_threshold', 3);
        $count = $this->model->countActiveSessions($userId);
        if ($count > $threshold) {
            $score += $this->policy->getInt('fraud', 'session.concurrent_points', 30);
            $anomalies[] = "{$count} Session همزمان فعال";
        }

        // User Agent change check
        $recentUA = $this->model->getRecentUserAgents($userId, 2);
        if (count($recentUA) >= 2 && !empty($recentUA[0]->created_at) && !empty($recentUA[1]->created_at)) {
            $t1 = strtotime((string)$recentUA[0]->created_at);
            $t2 = strtotime((string)$recentUA[1]->created_at);
            if ($t1 > 0 && $t2 > 0) {
                $timeDiff = abs($t1 - $t2);
                if ($timeDiff < self::SUSPICIOUS_UA_CHANGE_SECONDS && $recentUA[0]->user_agent !== $recentUA[1]->user_agent) {
                    $score += $this->policy->getInt('fraud', 'session.ua_change_points', 40);
                    $anomalies[] = 'تغییر ناگهانی User-Agent در کمتر از 5 دقیقه';
                }
            }
        }

        // Geo change check
        $recentGeo = $this->model->getRecentGeolocations($userId, 2);
        if (count($recentGeo) >= 2 && !empty($recentGeo[0]->created_at) && !empty($recentGeo[1]->created_at)) {
            $t1 = strtotime((string)$recentGeo[0]->created_at);
            $t2 = strtotime((string)$recentGeo[1]->created_at);
            if ($t1 > 0 && $t2 > 0) {
                $timeDiff = abs($t1 - $t2);
                if ($timeDiff < self::SUSPICIOUS_GEO_CHANGE_SECONDS && $recentGeo[0]->country !== $recentGeo[1]->country) {
                    $score += $this->policy->getInt('fraud', 'session.geo_change_points', 35);
                    $anomalies[] = "تغییر موقعیت از {$recentGeo[1]->country} به {$recentGeo[0]->country} در کمتر از 1 ساعت";
                }
            }
        }

        // Activity time check (2-6 AM) using User Timezone
        $timezone = $this->model->getUserTimezone($userId);
        
        // MED Fix: جلوگیری از کرش کشنده سیستم ناشی از مقادیر مخرب یا تهی Timezone در وهله‌سازی DateTimeZone
        $validTimezone = 'Asia/Tehran';
        try {
            if (!empty($timezone)) {
                new \DateTimeZone((string)$timezone); // تست کوچک برای ارزیابی صحت ساختار
                $validTimezone = $timezone;
            }
        } catch (\Throwable) {
            $validTimezone = 'Asia/Tehran';
        }

        $userDateTime = new \DateTime('now', new \DateTimeZone((string)$validTimezone));
        $hour = (int)$userDateTime->format('H');
        
        if ($hour >= self::UNUSUAL_HOUR_START && $hour <= self::UNUSUAL_HOUR_END) {
            $unusualCount = $this->model->getUnusualHourActivityCount($userId);
            if ($unusualCount > 5) {
                $score += $this->policy->getInt('fraud', 'session.activity_time_points', 15);
                $anomalies[] = 'فعالیت مکرر در ساعات غیرمعمول (2-6 صبح)';
            }
        }

        // Velocity check
        $actionCount = $this->model->getActionCount($userId, 1);
        $maxActions = $this->policy->getInt('fraud', 'session.max_actions_per_minute', self::MAX_ACTIONS_PER_MINUTE);
        if ($actionCount > $maxActions) {
            $score += $this->policy->getInt('fraud', 'session.velocity_points', 25);
            $anomalies[] = "{$actionCount} اقدام در 1 دقیقه (سرعت غیرطبیعی)";
        }

        $isAnomaly = $score >= 50;
        $this->logAnalysisResult($userId, $sessionId, $score, $anomalies, $isAnomaly);

        return [
            'is_anomaly' => $isAnomaly,
            'score' => min($score, 100),
            'anomalies' => $anomalies,
        ];
    }

    private function logAnalysisResult(int $userId, string $sessionId, int $score, array $anomalies, bool $isAnomaly): void
    {
        if ($score >= 80) {
            $this->logger->critical('session.anomaly.high_risk', ['user_id' => $userId, 'session_id' => $sessionId, 'score' => $score, 'anomalies' => $anomalies]);
        } elseif ($isAnomaly) {
            $this->logger->warning('session.anomaly.detected', ['user_id' => $userId, 'session_id' => $sessionId, 'score' => $score, 'anomalies' => $anomalies]);
        }

        if ($isAnomaly) {
            $this->model->logFraudEvent([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'type' => 'session_anomaly',
                'score' => $score,
                'details' => json_encode(['anomalies' => $anomalies], JSON_UNESCAPED_UNICODE)
            ]);
        }
    }

    /**
     * تجزیه User-Agent string
     */
    private function parseUserAgent(string $userAgent): array
    {
        // ✅ Check tablet first (before mobile, since iPad contains "ipad")
        $deviceType = 'desktop';
        if (preg_match('/tablet|ipad/i', $userAgent)) $deviceType = 'tablet';
        elseif (preg_match('/mobile|android|iphone/i', $userAgent)) $deviceType = 'mobile';

        $browser = 'Unknown';
        if (preg_match('/Chrome/i', $userAgent)) $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $userAgent)) $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $userAgent)) $browser = 'Safari';

        $os = 'Unknown';
        // ✅ Check specific mobile OS first (Android, iOS) before generic Linux
        if (preg_match('/Android/i', $userAgent)) $os = 'Android';
        elseif (preg_match('/iOS|iPhone|iPad/i', $userAgent)) $os = 'iOS';
        elseif (preg_match('/Windows/i', $userAgent)) $os = 'Windows';
        elseif (preg_match('/Mac/i', $userAgent)) $os = 'macOS';
        elseif (preg_match('/Linux/i', $userAgent)) $os = 'Linux';

        return ['device_type' => $deviceType, 'browser' => $browser, 'os' => $os];
    }

    /**
     * تولید Fingerprint از HTTP headers
     */
    private function generateFingerprint(
        string $userAgent,
        string $acceptLanguage = '',
        string $acceptEncoding = ''
    ): string {
        $entropy = $userAgent . '|' . $acceptLanguage . '|' . $acceptEncoding;
        return hash('sha256', $entropy);
    }

    /**
     * Set session cookie with proper security flags
     * 
     * HIGH-07 Fix: Ensures session cookies have httponly, secure, and samesite flags
     * to prevent XSS attacks from accessing cookies and CSRF attacks.
     */
    public function setSessionCookie(string $sessionId, bool $secure = true, int $lifetime = 0): void
    {
        $params = [
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $secure && $this->isHttps(),
            'httponly' => true,      // HIGH-07: Prevent JavaScript access
            'samesite' => 'Strict',  // HIGH-07: Strict CSRF protection (Lax for needed cross-site)
        ];
        
        // Use session_set_cookie_params if available (PHP built-in session)
        if (function_exists('session_set_cookie_params')) {
            session_set_cookie_params($params);
        }
        
        // For custom session handling, set cookie directly with security flags
        setcookie(
            session_name(),
            $sessionId,
            [
                'expires' => $lifetime > 0 ? time() + $lifetime : 0,
                'path' => '/',
                'domain' => '',
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Strict'
            ]
        );
    }
    
    /**
     * Check if request is over HTTPS
     */
    private function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            return true;
        }
        return false;
    }

}