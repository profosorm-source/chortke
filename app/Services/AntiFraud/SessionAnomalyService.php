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
class SessionAnomalyService
{
    private SecurityModel $model;
    private RiskPolicyService $policy;
    public function __construct(
        SecurityModel $model,
        RiskPolicyService $policy
    ) {        $this->model = $model;
        $this->policy = $policy;

            }

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

        // ۶. ناهنجاری‌های رفتاری (Behavioral Markers timing attack protection) - MEDIUM-NEW-01
        $session = $this->model->findSessionBySessionId($sessionId);
        if ($session) {
            // 1. Mouse movement entropy (low entropy suggests automated scripts)
            if (isset($session->mouse_entropy) && ((float)$session->mouse_entropy < 0.5)) {
                $score += 25;
                $anomalies[] = 'آنتروپی حرکت موس بسیار پایین (مشکوک به ربات)';
            }
            
            // 2. Scroll pattern (linear scroll suggests script automation)
            if (isset($session->scroll_data) && $this->detectLinearScroll((string)$session->scroll_data)) {
                $score += 20;
                $anomalies[] = 'الگوی اسکرول خطی و غیر طبیعی (مشکوک به ربات)';
            }
            
            // 3. Copy-paste detection without typing
            $pasteCount = isset($session->paste_count) ? (int)$session->paste_count : 0;
            $typeCount = isset($session->type_count) ? (int)$session->type_count : 0;
            if ($pasteCount > 0 && $typeCount < 5) {
                $score += 15;
                $anomalies[] = 'کپی-پیست مکرر بدون تایپ معمولی';
            }

            // 4. Duration anomaly (Timing anomaly)
            if (isset($session->duration) && (int)$session->duration < 10) {
                $score += 30;
                $anomalies[] = 'مدت زمان نشست بسیار کوتاه';
            }
        }

        return [
            'is_anomaly' => $score >= 50,
            'score' => min($score, 100),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * بررسی الگوی اسکرول خطی (خط مستقیم کاملاً ثابت نشان‌دهنده ربات است)
     */
    private function detectLinearScroll(?string $scrollData): bool
    {
        if (empty($scrollData)) {
            return false;
        }

        $points = json_decode($scrollData, true);
        if (!is_array($points) || count($points) < 5) {
            return false;
        }

        // Calculate slopes between consecutive points to see if they are perfectly linear
        $slopes = [];
        for ($i = 1; $i < count($points); $i++) {
            $dx = $points[$i]['x'] - $points[$i-1]['x'];
            $dy = $points[$i]['y'] - $points[$i-1]['y'];
            if ($dx == 0) {
                $slopes[] = 'inf';
            } else {
                $slopes[] = round($dy / $dx, 4);
            }
        }

        $uniqueSlopes = array_unique($slopes);
        // If there is only 1 unique slope across all points, it's a perfectly linear automated scroll
        return count($uniqueSlopes) === 1;
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
