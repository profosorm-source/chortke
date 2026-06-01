<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
use Core\Session;
class AccountTakeoverService
{
    private VelocityAndScoreModel $model;
    private SessionAnomalyService $sessionAnomaly;
    private IPQualityService $ipQuality;
    private RiskPolicyService $policy;
    private BrowserFingerprintService $fingerprintService;
    private Session $session;
    private GeoIPService $geoIPService;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model,
        SessionAnomalyService $sessionAnomaly,
        IPQualityService $ipQuality,
        RiskPolicyService $policy,
        BrowserFingerprintService $fingerprintService,
        Session $session,
        GeoIPService $geoIPService
    ) {        $this->logger = $logger;

                $this->model = $model;
        $this->sessionAnomaly = $sessionAnomaly;
        $this->ipQuality = $ipQuality;
        $this->policy = $policy;
        $this->fingerprintService = $fingerprintService;
        $this->session = $session;
        $this->geoIPService = $geoIPService;
    }

    public function detect(int $userId, string $ip, string $userAgent, ?string $fingerprint = null): array
    {
        // MED-07: اعتبارسنجی IP بر اساس فرمت معتبر
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->logger->warning('takeover.invalid_ip', [
                'raw_ip' => $ip,
                'user_id' => $userId,
                'user_agent' => $userAgent
            ]);
            $ip = '0.0.0.0'; // مقدار خنثی
        }

        $this->logger->info('takeover.detect.started', [
            'user_id' => $userId,
            'ip' => $ip
        ]);
        
        $riskScore = 0;
        $signals = [];

        $passwordCheck = $this->checkRecentPasswordChange($userId);
        if ($passwordCheck['suspicious']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.password_change_points', 40);
            $signals[] = $passwordCheck['signal'];
        }

        $emailCheck = $this->checkRecentEmailChange($userId);
        if ($emailCheck['suspicious']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.email_change_points', 35);
            $signals[] = $emailCheck['signal'];
        }

        $ipCheck = $this->checkNewIP($userId, $ip);
        if ($ipCheck['is_new']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.new_ip_points', 20);
            $signals[] = 'ورود از IP جدید';

            $ipQuality = $this->ipQuality->check($ip);
            if ($ipQuality['is_suspicious']) {
                $riskScore += $this->policy->getInt('fraud', 'takeover.suspicious_ip_bonus_points', 30);
                $signals[] = 'IP مشکوک: ' . implode(', ', $ipQuality['reasons']);
            }
        }

        $deviceCheck = $this->checkNewDevice($userId, $fingerprint, $userAgent);
        if ($deviceCheck['is_new']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.new_device_points', 15);
            $signals[] = 'ورود از دستگاه جدید';
        }

        // 🧠 Activity Hour Behavioral Baseline Correlator
        $timezone = $this->model->getUserTimezone($userId);
        $userDateTime = new \DateTime('now', new \DateTimeZone($timezone));
        $hour = (int)$userDateTime->format('H');
        
        // Static odd hour warning
        if ($hour >= 2 && $hour <= 6) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.odd_hour_points', 10);
            $signals[] = 'ورود در ساعت غیرمعمول (۲ تا ۶ صبح)';
        }

        // Dynamic baseline comparison: Compare current login hour with user's 30-day transaction log.
        try {
            $historicHours = $this->model->getHourlyActivity($userId, 30);
            if (!empty($historicHours) && !isset($historicHours[$hour])) {
                // The user has zero historic transactions in this specific hour bucket
                $riskScore += $this->policy->getInt('fraud', 'takeover.hourly_drift_points', 15);
                $signals[] = 'انحراف زمانی: ورود در ساعت مغایر با الگوی رفتاری تاریخی';
            }
        } catch (\Throwable $e) {
            $this->logger->warning('takeover.baseline_drift_check_failed', ['error' => $e->getMessage()]);
        }

        // 🛡️ Session Behavioral & Impossible Travel Correlator
        // M34 Fix: استفاده از شی سشن تزریق‌شده بجای تابع کمکی مستقیم سراسری
        $sessionId = $this->session->getId();
        if ($sessionId) {
            try {
                $sessionRes = $this->sessionAnomaly->analyze($userId, $sessionId);
                if (!empty($sessionRes['anomalies'])) {
                    // Increment by half of session risk score as a blended metric
                    $riskScore += (int)($sessionRes['score'] * 0.5);
                    foreach ($sessionRes['anomalies'] as $anomaly) {
                        $signals[] = "ناهنجاری رفتاری نشست: {$anomaly}";
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('takeover.session_correlator_failed', ['error' => $e->getMessage()]);
            }
        }

        // 🚀 Add Impossible Travel Detection
        try {
            $travelCheck = $this->checkImpossibleTravel($userId, $ip);
            if ($travelCheck['suspicious']) {
                $riskScore += $this->policy->getInt('fraud', 'takeover.impossible_travel_points', 90);
                $signals[] = $travelCheck['signal'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('takeover.impossible_travel_check_failed', ['error' => $e->getMessage()]);
        }

        $failedAttempts = $this->model->getRecentFailedAttempts($userId);
        $failedThreshold = $this->policy->getInt('fraud', 'takeover.failed_attempts_threshold', 3);
        if ($failedAttempts > $failedThreshold) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.failed_attempts_points', 25);
            $signals[] = "{$failedAttempts} تلاش ناموفق قبلی";
        }

        $riskScore = min($riskScore, 100);
        $isTakeover = $riskScore >= 70;
        $action = $this->determineAction($riskScore);

        // لاگ بر اساس سطح خطر
        if ($riskScore >= 90) {
            $this->logger->critical('takeover.detected.critical', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore,
                'signals' => $signals,
                'action' => $action
            ]);
        } elseif ($isTakeover) {
            $this->logger->error('takeover.detected.high', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore,
                'signals' => $signals,
                'action' => $action
            ]);
        } elseif ($riskScore >= 50) {
            $this->logger->warning('takeover.detected.medium', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore,
                'signals' => $signals
            ]);
        } else {
            $this->logger->info('takeover.check.clean', [
                'user_id' => $userId,
                'ip' => $ip,
                'risk_score' => $riskScore
            ]);
        }

        return [
            'is_takeover' => $isTakeover,
            'risk_score' => $riskScore,
            'signals' => $signals,
            'action' => $action,
        ];
    }

    private function checkRecentPasswordChange(int $userId): array
    {
        $lastChange = $this->model->getLastPasswordChange($userId);
        if ($lastChange && (time() - strtotime($lastChange)) < 3600) {
            return ['suspicious' => true, 'signal' => 'تغییر رمز عبور در 1 ساعت اخیر'];
        }

        return ['suspicious' => false];
    }

    private function checkRecentEmailChange(int $userId): array
    {
        $lastChange = $this->model->getLastEmailChange($userId);
        if ($lastChange && (time() - strtotime($lastChange)) < 3600) {
            return ['suspicious' => true, 'signal' => 'تغییر ایمیل در 1 ساعت اخیر'];
        }

        return ['suspicious' => false];
    }

    private function checkNewIP(int $userId, string $ip): array
    {
        $count = $this->model->getIPUsageCount($userId, $ip);
        return ['is_new' => $count === 0];
    }

    private function checkNewDevice(int $userId, ?string $fingerprint, string $userAgent): array
    {
        if ($fingerprint) {
            $existing = $this->fingerprintService->getUserFingerprints($userId, 50);
            foreach ($existing as $record) {
                if ($record->fingerprint === $fingerprint) {
                    return ['is_new' => false];
                }
            }
            return ['is_new' => true];
        }
        
        // Fallback to user agent if fingerprint is not available
        $count = $this->model->getDeviceUsageCount($userId, $userAgent);
        return ['is_new' => $count === 0];
    }

    private function determineAction(int $riskScore): string
    {
        if ($riskScore >= 90) {
            return 'block';
        }
        if ($riskScore >= 70) {
            return 'challenge';
        }
        if ($riskScore >= 50) {
            return 'notify';
        }
        return 'allow';
    }

    public function logDetection(int $userId, string $ip, string $userAgent, array $detection): void
    {
        if (!$detection['is_takeover']) {
            return;
        }

        $this->model->logTakeoverDetection($userId, $ip, $userAgent, $detection);
    }

    private function checkImpossibleTravel(int $userId, string $ip): array
    {
        $current = $this->geoIPService->lookup($ip);
        $last = $this->model->getLastLoginLocation($userId);
        
        if (!$last || !isset($last->latitude, $last->longitude)) {
            return ['suspicious' => false];
        }
        
        if (!isset($current['latitude'], $current['longitude'])) {
            return ['suspicious' => false];
        }
        
        $distance = $this->geoIPService->calculateDistance(
            [
                'latitude' => (float)$last->latitude,
                'longitude' => (float)$last->longitude
            ],
            [
                'latitude' => (float)$current['latitude'],
                'longitude' => (float)$current['longitude']
            ]
        );
        
        $timeDiff = time() - strtotime($last->login_at);
        
        if ($timeDiff < 60) {
            return ['suspicious' => false]; // کمتر از 1 دقیقه
        }
        
        $speedKmH = $distance / ($timeDiff / 3600);
        
        // غیرممکن بودن سرعت حرکت (مثلا بیش از ۱۰۰۰ کیلومتر بر ساعت)
        if ($speedKmH > 1000) {
            return [
                'suspicious' => true,
                'signal' => sprintf(
                    'Impossible travel: %d km in %d minutes (%.0f km/h) from %s to %s',
                    (int)$distance,
                    (int)($timeDiff / 60),
                    $speedKmH,
                    $last->city ?? 'Unknown',
                    $current['city'] ?? 'Unknown'
                )
            ];
        }
        
        return ['suspicious' => false];
    }
}
