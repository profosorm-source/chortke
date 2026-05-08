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
    public function __construct(
        private SecurityModel $model,
        private RiskPolicyService $policy,
        protected LoggerInterface $logger
    ) {}

    /**
     * ثبت نشست جدید
     */
    public function recordSession(int $userId, string $sessionId, ?array $geoData = null): bool
    {
        $deviceInfo = $this->parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');

        $existing = $this->model->findSessionBySessionId($sessionId);
        if ($existing) {
            return $this->model->updateSessionActivity($sessionId);
        }

        return $this->model->upsertSession([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'os' => $deviceInfo['os'],
            'country' => $geoData['country'] ?? null,
            'city' => $geoData['city'] ?? null,
            'fingerprint' => $this->generateFingerprint()
        ]);
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
            if ($timeDiff < 300 && $recentUA[0]->user_agent !== $recentUA[1]->user_agent) {
                $score += $this->policy->getInt('fraud', 'session.ua_change_points', 40);
                $anomalies[] = 'تغییر ناگهانی User-Agent در کمتر از 5 دقیقه';
            }
        }

        // Geo change check
        $recentGeo = $this->model->getRecentGeolocations($userId, 2);
        if (count($recentGeo) >= 2) {
            $timeDiff = strtotime((string)$recentGeo[0]->created_at) - strtotime((string)$recentGeo[1]->created_at);
            if ($timeDiff < 3600 && $recentGeo[0]->country !== $recentGeo[1]->country) {
                $score += $this->policy->getInt('fraud', 'session.geo_change_points', 35);
                $anomalies[] = "تغییر موقعیت از {$recentGeo[1]->country} به {$recentGeo[0]->country} در کمتر از 1 ساعت";
            }
        }

        // Activity time check (2-6 AM)
        $hour = (int)date('H');
        if ($hour >= 2 && $hour <= 6) {
            $unusualCount = $this->model->getUnusualHourActivityCount($userId);
            if ($unusualCount > 5) {
                $score += $this->policy->getInt('fraud', 'session.activity_time_points', 15);
                $anomalies[] = 'فعالیت مکرر در ساعات غیرمعمول (2-6 صبح)';
            }
        }

        // Velocity check
        $actionCount = $this->model->getActionCount($userId, 1);
        if ($actionCount > 20) {
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

    private function parseUserAgent(string $userAgent): array
    {
        $deviceType = 'desktop';
        if (preg_match('/mobile|android|iphone|ipad/i', $userAgent)) $deviceType = 'mobile';
        elseif (preg_match('/tablet|ipad/i', $userAgent)) $deviceType = 'tablet';

        $browser = 'Unknown';
        if (preg_match('/Chrome/i', $userAgent)) $browser = 'Chrome';
        elseif (preg_match('/Firefox/i', $userAgent)) $browser = 'Firefox';
        elseif (preg_match('/Safari/i', $userAgent)) $browser = 'Safari';

        $os = 'Unknown';
        if (preg_match('/Windows/i', $userAgent)) $os = 'Windows';
        elseif (preg_match('/Mac/i', $userAgent)) $os = 'macOS';
        elseif (preg_match('/Linux/i', $userAgent)) $os = 'Linux';
        elseif (preg_match('/Android/i', $userAgent)) $os = 'Android';
        elseif (preg_match('/iOS|iPhone|iPad/i', $userAgent)) $os = 'iOS';

        return ['device_type' => $deviceType, 'browser' => $browser, 'os' => $os];
    }

    private function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function generateFingerprint(): string
    {
        return md5(($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
    }
}
