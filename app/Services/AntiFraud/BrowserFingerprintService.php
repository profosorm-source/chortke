<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\IpAndDeviceModel;

use App\Contracts\LoggerInterface;
class BrowserFingerprintService extends \App\Services\BaseService
{
    private IpAndDeviceModel $model;
    
    public function __construct(IpAndDeviceModel $model, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->model = $model;
    }
    
    /**
     * ایجاد Fingerprint کامل
     */
    public function generate(array $data): string
    {
        // MED-02: Ensuring array consistency and logging suspicious empty submissions
        if (empty($data) || !isset($data['user_agent'])) {
            $this->logger->warning('fingerprint.generate.incomplete_payload', ['payload_keys' => array_keys($data)]);
        }

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
            // MED-01: Introduce corporate and whitelist exceptions to bypass shared device alerts
            if ($this->isExemptFromSharedChecks($userId, $fingerprint)) {
                $this->logger->info('fingerprint.analyze.exempted', ['user_id' => $userId, 'fingerprint' => $fingerprint]);
            } else {
                $suspicionScore += 40;
                $reasons[] = "Fingerprint مشترک با {$userCount} کاربر";
            }
        }
        
        // 2. بررسی تغییر ناگهانی Fingerprint
        $fingerprints = $this->model->getRecentFingerprints($userId, 2);
        
        if (count($fingerprints) > 1) {
            $timeDiff = strtotime((string)$fingerprints[0]->created_at) - strtotime((string)$fingerprints[1]->created_at);
            
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
     * Checks whether the user enjoys shared fingerprint allowances (Corporate, Family, VIP).
     */
    private function isExemptFromSharedChecks(int $userId, string $fingerprint): bool
    {
        try {
            // Evaluates status, corporate alignment or direct administrative device whitelisting.
            $exemptCount = $this->model->fetch(
                "SELECT COUNT(*) as count FROM users WHERE id = ? AND (is_corporate = 1 OR status = 'whitelisted')",
                [$userId]
            );
            return (int)($exemptCount->count ?? 0) > 0;
        } catch (\Throwable $e) {
            return false; // Secure fallback: alert rather than leak
        }
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
        // LOW-01: Default safety duration to 30 days if none specified
        if ($duration === null) {
            $duration = 30 * 24 * 3600;
        }

        $expiresAt = date('Y-m-d H:i:s', time() + $duration);
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

