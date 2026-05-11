<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\SeoExecution;
use App\Models\IpAndDeviceModel;
use App\Contracts\LoggerInterface;
/**
 * SeoFraudDetector — تشخیص تقلب در تعاملات SEO
 */
class SeoFraudDetector extends \App\Services\BaseService
{
    private BrowserFingerprintService $fingerprintService;
    private SessionAnomalyService $anomalyService;
    private SeoExecution $executionModel;
    private IpAndDeviceModel $model;
    public function __construct(
        BrowserFingerprintService $fingerprintService,
        SessionAnomalyService $anomalyService,
        SeoExecution $executionModel,
        IpAndDeviceModel $model,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
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

        $duration = $data['duration'] ?? 0;
        $score = $data['final_score'] ?? 0;
        
        if ($duration < 30 && $score > 80) {
            $suspicious = true;
            $reasons[] = 'امتیاز بالا در زمان خیلی کوتاه';
            $riskScore += 30;
        }

        $interactions = $data['interactions'] ?? 0;
        if ($interactions === 0 && $duration > 60) {
            $suspicious = true;
            $reasons[] = 'عدم تعامل با حضور طولانی';
            $riskScore += 25;
        }

        $scrollSpeed = $data['behavior']['scroll_speed'] ?? 0;
        if ($scrollSpeed > 5000) {
            $suspicious = true;
            $reasons[] = 'سرعت اسکرول غیرطبیعی';
            $riskScore += 20;
        }

        $mousePattern = $data['behavior']['mouse_pattern'] ?? 'normal';
        if ($mousePattern === 'linear' || $mousePattern === 'none') {
            $suspicious = true;
            $reasons[] = 'الگوی حرکت موس مشکوک';
            $riskScore += 15;
        }

        return [
            'suspicious' => $suspicious,
            'reasons' => $reasons,
            'risk_score' => $riskScore,
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

