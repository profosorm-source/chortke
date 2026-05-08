<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\IpAndDeviceModel;

use App\Contracts\LoggerInterface;
class BrowserFingerprintService extends \App\Services\BaseService
{
    private IpAndDeviceModel $model;
    
    public function __construct(IpAndDeviceModel $model)
    {
        $this->model = $model;
    }
    
    /**
     * ایجاد Fingerprint کامل
     */
    public function generate(array $data): string
    {
        $components = [
            'user_agent' => $data['user_agent'] ?? '',
            'language' => $data['language'] ?? '',
            'timezone' => $data['timezone'] ?? '',
            'screen' => $data['screen'] ?? '',
            'canvas' => $data['canvas'] ?? '',
            'webgl' => $data['webgl'] ?? '',
            'audio' => $data['audio'] ?? '',
            'fonts' => $data['fonts'] ?? '',
            'plugins' => $data['plugins'] ?? '',
            'touch_support' => $data['touch_support'] ?? '',
            'hardware_concurrency' => $data['hardware_concurrency'] ?? '',
            'device_memory' => $data['device_memory'] ?? ''
        ];
        
        return hash('sha256', json_encode($components));
    }
    
    /**
     * ذخیره Fingerprint
     */
    public function store(int $userId, string $fingerprint, array $metadata): void
    {
        $this->model->storeFingerprint($userId, $fingerprint, $metadata);
    }
    
    /**
     * بررسی Fingerprint مشکوک
     */
    public function analyze(int $userId, string $fingerprint): array
    {
        $suspicionScore = 0;
        $reasons = [];
        
        // 1. بررسی تعداد کاربران با همین Fingerprint
        $userCount = $this->model->getFingerprintUserCount($fingerprint);
        
        if ($userCount > 3) {
            $suspicionScore += 40;
            $reasons[] = "Fingerprint مشترک با {$userCount} کاربر";
        }
        
        // 2. بررسی تغییر ناگهانی Fingerprint
        $fingerprints = $this->model->getRecentFingerprints($userId, 2);
        
        if (count($fingerprints) > 1) {
            $timeDiff = strtotime($fingerprints[0]->created_at) - strtotime($fingerprints[1]->created_at);
            
            if ($timeDiff < 3600 && $fingerprints[0]->fingerprint !== $fingerprints[1]->fingerprint) {
                $suspicionScore += 25;
                $reasons[] = "تغییر ناگهانی Fingerprint در کمتر از 1 ساعت";
            }
        }
        
        return [
            'suspicious' => $suspicionScore >= 50,
            'score' => $suspicionScore,
            'reasons' => $reasons
        ];
    }
    
    /**
     * دریافت Fingerprint های کاربر
     */
    public function getUserFingerprints(int $userId, int $limit = 10): array
    {
        return $this->model->getAllUserFingerprints($userId, $limit);
    }
    
    /**
     * بررسی Fingerprint در لیست سیاه
     */
    public function isFingerprintBlacklisted(string $fingerprint): bool
    {
        return $this->model->isBlacklisted($fingerprint);
    }
    
    /**
     * اضافه کردن Fingerprint به لیست سیاه
     */
    public function blacklistFingerprint(string $fingerprint, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        $this->model->blacklistFingerprint($fingerprint, $reason, $expiresAt);
    }
    
    /**
     * لاگ کردن تحلیل Fingerprint
     */
    public function logAnalysis(int $userId, string $fingerprint, array $analysis): void
    {
        if ($analysis['suspicious']) {
            $this->model->logSuspicion($userId, $analysis['score'], $analysis);
        }
    }
}

