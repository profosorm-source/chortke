<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\SeoExecution;
use App\Models\IpAndDeviceModel;
use App\Contracts\LoggerInterface;
/**
 * SeoFraudDetector — تشخیص تقلب در تعاملات SEO
 */
class SeoFraudDetector
{
    private BrowserFingerprintService $fingerprintService;
    private SessionAnomalyService $anomalyService;
    private SeoExecution $executionModel;
    private IpAndDeviceModel $model;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        BrowserFingerprintService $fingerprintService,
        SessionAnomalyService $anomalyService,
        SeoExecution $executionModel,
        IpAndDeviceModel $model
    ) {        $this->logger = $logger;

                $this->fingerprintService = $fingerprintService;
        $this->anomalyService = $anomalyService;
        $this->executionModel = $executionModel;
        $this->model = $model;
    }

    /**
     * بررسی جامع تقلب
     */
    public function detect(int $userId, int $adId, array $engagementData): array
    {
        $flags = [];
        $riskScore = 0;

        $fingerprintCheck = $this->checkFingerprint($userId);
        if ($fingerprintCheck['suspicious']) {
            $flags[] = $fingerprintCheck['reason'];
            $riskScore += 25;
        }

        $ipCheck = $this->checkIP($userId);
        if ($ipCheck['suspicious']) {
            $flags[] = $ipCheck['reason'];
            $riskScore += 20;
        }

        $behaviorCheck = $this->checkBehaviorPattern($engagementData);
        if ($behaviorCheck['suspicious']) {
            $flags = array_merge($flags, $behaviorCheck['reasons']);
            $riskScore += $behaviorCheck['risk_score'];
        }

        $repetitionCheck = $this->checkRepetition($userId, $adId);
        if ($repetitionCheck['suspicious']) {
            $flags[] = $repetitionCheck['reason'];
            $riskScore += 15;
        }

        $velocityCheck = $this->checkVelocity($userId);
        if ($velocityCheck['suspicious']) {
            $flags[] = $velocityCheck['reason'];
            $riskScore += 20;
        }

        $isFraud = $riskScore >= 50;

        return [
            'is_fraud' => $isFraud,
            'flags' => $flags,
            'risk_score' => min(100, $riskScore),
            'details' => [
                'fingerprint' => $fingerprintCheck,
                'ip' => $ipCheck,
                'behavior' => $behaviorCheck,
                'repetition' => $repetitionCheck,
                'velocity' => $velocityCheck,
            ]
        ];
    }

    private function checkFingerprint(int $userId): array
    {
        try {
            $deviceCount = $this->model->getDeviceCountLast7Days($userId);
            
            if ($deviceCount > 5) {
                return [
                    'suspicious' => true,
                    'reason' => 'استفاده از دستگاه‌های متعدد',
                    'device_count' => $deviceCount,
                ];
            }

            return ['suspicious' => false];
        } catch (\Exception $e) {
            $this->logger->error('seo_fraud.fingerprint_check_failed', ['error' => $e->getMessage()]);
            return ['suspicious' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkIP(int $userId): array
    {
        $ip = get_client_ip();
        
        if ($this->isVpnOrProxy($ip)) {
            return [
                'suspicious' => true,
                'reason' => 'استفاده از VPN/Proxy',
                'ip' => $ip,
            ];
        }

        $ipCount = $this->model->getIPCountLast24Hours($userId);
        
        if ($ipCount > 3) {
            return [
                'suspicious' => true,
                'reason' => 'IP های متعدد در 24 ساعت',
                'ip_count' => $ipCount,
            ];
        }

        return ['suspicious' => false];
    }

    private function checkBehaviorPattern(array $data): array
    {
        $suspicious = false;
        $reasons = [];
        $riskScore = 0;

        $duration = (float)($data['duration'] ?? 0);
        $score = (float)($data['final_score'] ?? 0);
        
        // ۱. سرعت عمل غیرطبیعی
        if ($duration < 30 && $score > 80) {
            $suspicious = true;
            $reasons[] = 'امتیاز بالا در زمان خیلی کوتاه';
            $riskScore += 30;
        }

        $interactions = (int)($data['interactions'] ?? 0);
        if ($interactions === 0 && $duration > 60) {
            $suspicious = true;
            $reasons[] = 'عدم تعامل با حضور طولانی';
            $riskScore += 25;
        }

        // ۲. محاسبه انحراف معیار و آنتروپی زمانی کلیک‌ها و رفتارها
        $clickTimings = $data['behavior']['click_timings'] ?? [];
        if (!empty($clickTimings) && count($clickTimings) >= 3) {
            $intervals = [];
            for ($i = 1; $i < count($clickTimings); $i++) {
                $intervals[] = $clickTimings[$i] - $clickTimings[$i - 1];
            }
            // محاسبه میانگین و انحراف معیار فواصل زمانی
            $mean = array_sum($intervals) / count($intervals);
            $variance = 0.0;
            foreach ($intervals as $val) {
                $variance += pow($val - $mean, 2);
            }
            $stdDev = sqrt($variance / count($intervals));

            // اگر انحراف معیار به شکل ربات‌گونه‌ای بسیار کوچک باشد (زیر ۵ میلی‌ثانیه یعنی فواصل تکراری بی‌نقص)
            if ($stdDev < 0.005) {
                $suspicious = true;
                $reasons[] = 'فواصل زمانی کلیک‌ها غیرطبیعی و کاملاً منظم (ربات)';
                $riskScore += 35;
            }
        }

        // ۳. سرعت و شتاب حرکت موس
        $mouseSpeeds = $data['behavior']['mouse_speeds'] ?? [];
        if (!empty($mouseSpeeds) && count($mouseSpeeds) >= 4) {
            $meanSpeed = array_sum($mouseSpeeds) / count($mouseSpeeds);
            $varSpeed = 0.0;
            foreach ($mouseSpeeds as $s) {
                $varSpeed += pow($s - $meanSpeed, 2);
            }
            $stdDevSpeed = sqrt($varSpeed / count($mouseSpeeds));

            // نوسان سرعت حرکت انسان همیشه بالاست؛ نوسان ثابت یعنی حرکت خطی ربات
            if ($stdDevSpeed < 1.0) {
                $suspicious = true;
                $reasons[] = 'الگوی سرعت حرکت موس خطی و بدون شتاب طبیعی';
                $riskScore += 30;
            }
        }

        // ۴. بررسی اسکرول خطی (Linear Scrolling Momentum)
        $scrollPattern = $data['behavior']['scroll_pattern'] ?? 'natural';
        if ($scrollPattern === 'linear') {
            $suspicious = true;
            $reasons[] = 'اسکرول خطی و بدون فیزیک حرکتی طبیعی';
            $riskScore += 20;
        }

        // ۵. نسبت رویدادهای کیبورد به موس
        $mouseEvents = (int)($data['behavior']['mouse_events_count'] ?? 0);
        $keyEvents = (int)($data['behavior']['key_events_count'] ?? 0);
        if ($keyEvents > 0 && $mouseEvents === 0) {
            $suspicious = true;
            $reasons[] = 'تعامل صرفاً کیبوردی بدون هیچ رویداد موس';
            $riskScore += 15;
        }

        return [
            'suspicious' => $suspicious || ($riskScore >= 40),
            'reasons' => $reasons,
            'risk_score' => min($riskScore, 100),
        ];
    }

    private function checkRepetition(int $userId, int $adId): array
    {
        if ($this->executionModel->existsByAdAndUserToday($adId, $userId)) {
            return [
                'suspicious' => true,
                'reason' => 'تلاش برای اجرای مجدد در یک روز',
            ];
        }

        return ['suspicious' => false];
    }

    private function checkVelocity(int $userId): array
    {
        $hourlyCount = $this->executionModel->countByUserLastHour($userId);
        
        if ($hourlyCount > 10) {
            return [
                'suspicious' => true,
                'reason' => 'تعداد درخواست بیش از حد در ساعت',
                'hourly_count' => $hourlyCount,
            ];
        }

        return ['suspicious' => false];
    }

    private function isVpnOrProxy(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        
        return false;
    }

    public function addToBlacklist(int $userId, string $reason): bool
    {
        return $this->model->addToBlacklist($userId, $reason);
    }

    public function isBlacklisted(int $userId): bool
    {
        return $this->model->isBlacklisted($userId);
    }

    public function smoothScore(float $score, array $history): float
    {
        if (count($history) < 3) {
            return $score * 0.8;
        }

        $avgScore = array_sum(array_column($history, 'final_score')) / count($history);
        
        if ($score > $avgScore + 30) {
            return min($score, $avgScore + 20);
        }

        return $score;
    }
}

