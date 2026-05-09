<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
/**
 * SessionService
 *
 * مدیریت نشست‌های کاربری و تحلیل ناهنجاری‌ها.
 */
class SessionService extends \App\Services\BaseService
{
    public const SUSPICIOUS_UA_CHANGE_SECONDS = 300;
    public const SUSPICIOUS_GEO_CHANGE_SECONDS = 3600;
    public const UNUSUAL_HOUR_START = 2;
    public const UNUSUAL_HOUR_END = 6;
    public const MAX_ACTIONS_PER_MINUTE = 20;

    public function __construct(
        private SecurityModel $model,
        private RiskPolicyService $policy,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
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

        // ✅ استفاده از parameters، نه $_SERVER
        $deviceInfo = $this->parseUserAgent($userAgent);
        $fingerprint = $this->generateFingerprint($userAgent, $acceptLanguage, $acceptEncoding);

        try {
            // 🔒 PESSIMISTIC LOCKING: Prevent race condition in session creation
            $this->model->getDb()->beginTransaction();

            // Lock existing session if it exists
            $existing = $this->model->getDb()->selectOne(
                "SELECT id FROM sessions WHERE session_id = ? FOR UPDATE",
                [$sessionId]
            );

            if ($existing) {
                // Session exists, just update activity timestamp
                $result = $this->model->updateSessionActivity($sessionId);
                $this->model->getDb()->commit();
                return $result;
            }

            // Session doesn't exist, create it
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

            $this->model->getDb()->commit();
            return $result;

        } catch (\Exception $e) {
            $this->model->getDb()->rollback();
            $this->logger->error('session.record_session.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
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

    public function terminateSession(string $sessionId, int $userId): array
    {
        $session = $this->model->findSessionBySessionId($sessionId);
        if (!$session || (int)$session->user_id !== $userId) {
            return ['success' => false, 'message' => 'نشست یافت نشد'];
        }

        $this->model->deactivateSession((int)$session->id);
        return ['success' => true, 'message' => 'نشست با موفقیت حذف شد'];
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
        if (count($recentUA) >= 2) {
            $timeDiff = strtotime((string)$recentUA[0]->created_at) - strtotime((string)$recentUA[1]->created_at);
            if ($timeDiff < self::SUSPICIOUS_UA_CHANGE_SECONDS && $recentUA[0]->user_agent !== $recentUA[1]->user_agent) {
                $score += $this->policy->getInt('fraud', 'session.ua_change_points', 40);
                $anomalies[] = 'تغییر ناگهانی User-Agent در کمتر از 5 دقیقه';
            }
        }

        // Geo change check
        $recentGeo = $this->model->getRecentGeolocations($userId, 2);
        if (count($recentGeo) >= 2) {
            $timeDiff = strtotime((string)$recentGeo[0]->created_at) - strtotime((string)$recentGeo[1]->created_at);
            if ($timeDiff < self::SUSPICIOUS_GEO_CHANGE_SECONDS && $recentGeo[0]->country !== $recentGeo[1]->country) {
                $score += $this->policy->getInt('fraud', 'session.geo_change_points', 35);
                $anomalies[] = "تغییر موقعیت از {$recentGeo[1]->country} به {$recentGeo[0]->country} در کمتر از 1 ساعت";
            }
        }

        // Activity time check (2-6 AM)
        $hour = (int)date('H');
        if ($hour >= self::UNUSUAL_HOUR_START && $hour <= self::UNUSUAL_HOUR_END) {
            $unusualCount = $this->model->getUnusualHourActivityCount($userId);
            if ($unusualCount > 5) {
                $score += $this->policy->getInt('fraud', 'session.activity_time_points', 15);
                $anomalies[] = 'فعالیت مکرر در ساعات غیرمعمول (2-6 صبح)';
            }
        }

        // Velocity check
        $actionCount = $this->model->getActionCount($userId, 1);
        if ($actionCount > self::MAX_ACTIONS_PER_MINUTE) {
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

}
