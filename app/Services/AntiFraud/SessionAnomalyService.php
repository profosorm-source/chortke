<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\SecurityModel;
use App\Services\AntiFraud\RiskPolicyService;

use App\Contracts\LoggerInterface;
/**
 * SessionAnomalyService
 * 
 * تحلیل ناهنجاری‌های نشست‌های کاربری با استفاده از SecurityModel.
 */
class SessionAnomalyService extends \App\Services\BaseService
{
    public function __construct(
        private SecurityModel $model,
        private RiskPolicyService $policy
    ) {}

    /**
     * تحلیل ناهنجاری برای یک نشست
     */
    public function analyze(int $userId, string $sessionId): array
    {
        $anomalies = [];
        $score = 0;

        // ۱. تعداد نشست‌های همزمان
        $threshold = $this->policy->getInt('fraud', 'session.concurrent_threshold', 3);
        $count = $this->model->countActiveSessions($userId);
        if ($count > $threshold) {
            $score += $this->policy->getInt('fraud', 'session.concurrent_points', 30);
            $anomalies[] = "{$count} Session همزمان فعال";
        }

        // ۲. تغییر User-Agent
        $sessions = $this->model->getRecentUserAgents($userId, 2);
        if (count($sessions) >= 2) {
            $timeDiff = strtotime((string)$sessions[0]->created_at) - strtotime((string)$sessions[1]->created_at);
            if ($timeDiff < 300 && $sessions[0]->user_agent !== $sessions[1]->user_agent) {
                $score += $this->policy->getInt('fraud', 'session.ua_change_points', 40);
                $anomalies[] = 'تغییر ناگهانی User-Agent در کمتر از 5 دقیقه';
            }
        }

        // ۳. تغییر موقعیت جغرافیایی
        $geoSessions = $this->model->getRecentGeolocations($userId, 2);
        if (count($geoSessions) >= 2) {
            $timeDiff = strtotime((string)$geoSessions[0]->created_at) - strtotime((string)$geoSessions[1]->created_at);
            if ($timeDiff < 3600 && $geoSessions[0]->country !== $geoSessions[1]->country) {
                $score += $this->policy->getInt('fraud', 'session.geo_change_points', 35);
                $anomalies[] = "تغییر موقعیت از {$geoSessions[1]->country} به {$geoSessions[0]->country} در کمتر از 1 ساعت";
            }
        }

        // ۴. فعالیت در ساعات غیرمعمول (۲-۶ صبح)
        $hour = (int)date('H');
        if ($hour >= 2 && $hour <= 6) {
            $unusualCount = $this->model->getUnusualHourActivityCount($userId);
            if ($unusualCount > 5) {
                $score += $this->policy->getInt('fraud', 'session.activity_time_points', 15);
                $anomalies[] = 'فعالیت مکرر در ساعات غیرمعمول (2-6 صبح)';
            }
        }

        // ۵. سرعت اقدامات (Velocity)
        $actionCount = $this->model->getActionCount($userId, 1);
        if ($actionCount > 20) {
            $score += $this->policy->getInt('fraud', 'session.velocity_points', 25);
            $anomalies[] = "{$actionCount} اقدام در 1 دقیقه (سرعت غیرطبیعی)";
        }

        return [
            'is_anomaly' => $score >= 50,
            'score' => min($score, 100),
            'anomalies' => $anomalies,
        ];
    }

    public function logAnomaly(int $userId, string $sessionId, array $analysis): void
    {
        if (!$analysis['is_anomaly']) {
            return;
        }

        $this->model->logFraudEvent([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'type' => 'session_anomaly',
            'score' => (int)$analysis['score'],
            'details' => json_encode($analysis, JSON_UNESCAPED_UNICODE)
        ]);
    }
}
