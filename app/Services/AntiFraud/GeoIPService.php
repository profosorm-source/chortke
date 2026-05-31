<?php

namespace App\Services\AntiFraud;

use Core\Database;
use Core\Cache;
use App\Models\IpAndDeviceModel;
use App\Services\AntiFraud\RiskPolicyService;

use App\Contracts\LoggerInterface;
/**
 * GeoIPService - سرویس تشخیص موقعیت جغرافیایی
 * 
 * این سرویس برای تشخیص موقعیت جغرافیایی IP استفاده می‌شود
 * از MaxMind GeoIP2 یا دیتابیس محلی استفاده می‌کند
 */
class GeoIPService
{
    private IpAndDeviceModel $model;
    private RiskPolicyService $policy;
    private ?string $maxmindLicenseKey;
    private string $databasePath;
    private bool $useMaxMind = false;
    private $reader = null;

    
    public function __construct(
        Database $db,
        Cache $cache,
        IpAndDeviceModel $model,
        RiskPolicyService $policy,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->model = $model;
        $this->policy = $policy;
        $this->maxmindLicenseKey = config('services.geoip.maxmind_license_key', '');
        $this->databasePath = dirname(__DIR__, 2) . '/storage/geoip/';
        
        // بررسی وجود MaxMind
        $this->checkMaxMindAvailability();
    }

    /**
     * بررسی اینکه آیا IP بلاک شده است یا خیر
     */
    public function isIPBlacklisted(string $ip): bool
    {
        return $this->model->isIpBlacklisted($ip);
    }

    
    /**
     * دریافت اطلاعات جغرافیایی IP
     */
    public function lookup(string $ip): array
    {
        // بررسی IP خصوصی
        if ($this->isPrivateIP($ip)) {
            return $this->getDefaultLocation();
        }
        
        // بررسی کش
        $cached = $this->getCachedLocation($ip);
        if ($cached) {
            return $cached;
        }
        
        // تلاش برای استفاده از MaxMind
        if ($this->useMaxMind) {
            $result = $this->lookupMaxMind($ip);
            if ($result) {
                $this->cacheLocation($ip, $result);
                return $result;
            }
        }
        
        // Fallback به دیتابیس محلی
        $result = $this->lookupLocalDatabase($ip);
        
        if ($result) {
            $this->cacheLocation($ip, $result);
            return $result;
        }
        
        // H19 Fix: ممانعت از انتساب نامشخص‌ها به عنوان ایران (جلوگیری از تشخیص غلط آی‌پی‌های خارجی و IPv6)
        return $this->getUnknownLocation($ip);
    }
    
    /**
     * بررسی وجود MaxMind GeoIP2
     */
    private function checkMaxMindAvailability(): void
    {
        // بررسی وجود کتابخانه MaxMind
        if (!class_exists('\GeoIp2\Database\Reader')) {
            return;
        }
        
        // بررسی وجود فایل دیتابیس
        $dbFile = $this->databasePath . 'GeoLite2-City.mmdb';
        
        if (file_exists($dbFile)) {
            try {
                $this->reader = new \GeoIp2\Database\Reader($dbFile);
                $this->useMaxMind = true;
            } catch (\Throwable $e) {
                error_log("MaxMind Reader initialization failed: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Lookup با MaxMind
     */
    private function lookupMaxMind(string $ip): ?array
    {
        if (!$this->reader) {
            return null;
        }
        
        try {
            $record = $this->reader->city($ip);
            
            return [
                'ip' => $ip,
                'country_code' => $record->country->isoCode ?? 'IR',
                'country_name' => $record->country->name ?? 'Iran',
                'city' => $record->city->name ?? 'Tehran',
                'latitude' => $record->location->latitude ?? 35.6892,
                'longitude' => $record->location->longitude ?? 51.3890,
                'timezone' => $record->location->timeZone ?? 'Asia/Tehran',
                'postal_code' => $record->postal->code ?? null,
                'accuracy_radius' => $record->location->accuracyRadius ?? null,
                'source' => 'maxmind',
            ];
        } catch (\Throwable $e) {
            error_log("MaxMind lookup failed for IP {$ip}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Lookup از دیتابیس محلی (IP ranges)
     */
    /**
     * Lookup از دیتابیس محلی (IP ranges)
     */
    private function lookupLocalDatabase(string $ip): ?array
    {
        try {
            // دیتابیس ip_locations بر پایه IPv4 range است
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return null;
            }

            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                return null;
            }

            // Decoupled: Delegates native table scanning to the Model
            $result = $this->model->getLocationByIpRange($ipLong);

            if ($result) {
                return [
                    'ip' => $ip,
                    'country_code' => $result->country_code ?? 'IR',
                    'country_name' => $result->country_name ?? 'Iran',
                    'city' => $result->city ?? 'Tehran',
                    'latitude' => (float)($result->latitude ?? 35.6892),
                    'longitude' => (float)($result->longitude ?? 51.3890),
                    'timezone' => 'Asia/Tehran',
                    'source' => 'local_db',
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->error("antifraud.geoip_lookup.local_db_failed", ['error' => $e->getMessage()]);
        }

        return null;
    }
    /**
     * دریافت از کش
     */
    private function getCachedLocation(string $ip): ?array
    {
        $cached = $this->cache->get('geoip:' . $ip);
        
        return $cached ? json_decode($cached, true) : null;
    }
    
    /**
     * ذخیره در کش
     */
    private function cacheLocation(string $ip, array $data): void
    {
        // کش برای ۷ روز
        $this->cache->put('geoip:' . $ip, json_encode($data), 60 * 24 * 7);
    }
    
    /**
     * بررسی IP خصوصی
     */
private function isPrivateIP(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            // اگر فرمت آی‌پی اصلاً معتبر نیست، یک آی‌پی خصوصی در نظر گرفته نمی‌شود (بلکه کلاً نامعتبر است)
            return false; 
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
    
    /**
     * بررسی قرار گرفتن IP در یک Range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int)$mask);
        
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
    
    /**
     * موقعیت پیش‌فرض (ایران)
     */
    private function getDefaultLocation(): array
    {
        return [
            'ip' => '',
            'country_code' => 'IR',
            'country_name' => 'Iran',
            'city' => 'Tehran',
            'latitude' => 35.6892,
            'longitude' => 51.3890,
            'timezone' => 'Asia/Tehran',
            'source' => 'default',
        ];
    }

    /**
     * H19 Support: موقعیت نامشخص جهت جلوگیری از انتساب کور به ایران
     */
    private function getUnknownLocation(string $ip): array
    {
        return [
            'ip' => $ip,
            'country_code' => 'XX',
            'country_name' => 'Unknown',
            'city' => 'Unknown',
            'latitude' => 0.0,
            'longitude' => 0.0,
            'timezone' => 'UTC',
            'source' => 'unknown',
        ];
    }
    
    /**
     * بررسی اینکه IP از کدام کشور است
     */
    public function getCountryCode(string $ip): string
    {
        $location = $this->lookup($ip);
        return $location['country_code'] ?? 'IR';
    }
    
    /**
     * بررسی اینکه IP ایرانی است یا خیر
     */
    public function isIranianIP(string $ip): bool
    {
        return $this->getCountryCode($ip) === 'IR';
    }
    
    /**
     * محاسبه فاصله بین دو موقعیت (کیلومتر)
     */
    public function calculateDistance(array $location1, array $location2): float
    {
        $lat1 = deg2rad($location1['latitude']);
        $lon1 = deg2rad($location1['longitude']);
        $lat2 = deg2rad($location2['latitude']);
        $lon2 = deg2rad($location2['longitude']);
        
        $earthRadius = 6371; // کیلومتر
        
        $latDiff = $lat2 - $lat1;
        $lonDiff = $lon2 - $lon1;
        
        $a = sin($latDiff / 2) * sin($latDiff / 2) +
             cos($lat1) * cos($lat2) *
             sin($lonDiff / 2) * sin($lonDiff / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
    
    /**
     * دانلود و بروزرسانی دیتابیس MaxMind
     * این تابع باید هر ماه یکبار از طریق cron اجرا شود
     */
    public function updateMaxMindDatabase(): array
    {
        if (empty($this->maxmindLicenseKey)) {
            return [
                'success' => false,
                'message' => 'MaxMind license key not configured',
            ];
        }
        
        try {
            $url = "https://download.maxmind.com/app/geoip_download?" . http_build_query([
                'edition_id' => 'GeoLite2-City',
                'license_key' => $this->maxmindLicenseKey,
                'suffix' => 'tar.gz',
            ]);
            
            $downloadPath = $this->databasePath . 'GeoLite2-City.tar.gz';
            
            // ایجاد دایرکتوری اگر وجود نداشت
            if (!is_dir($this->databasePath)) {
                mkdir($this->databasePath, 0755, true);
            }
            
            // دانلود فایل
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5.0, // 5 seconds
                ]
            ]);
            $fileContent = @file_get_contents($url, false, $context);
            
            if ($fileContent === false) {
                throw new \Exception('Failed to download GeoIP database');
            }
            
            file_put_contents($downloadPath, $fileContent);
            
            // استخراج فایل
            $this->extractGeoIPDatabase($downloadPath);
            
            // حذف فایل فشرده
            unlink($downloadPath);
            
            return [
                'success' => true,
                'message' => 'MaxMind database updated successfully',
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
        } catch (\Throwable $e) {
            error_log("MaxMind database update failed: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * استخراج فایل دیتابیس از tar.gz
     */
    private function extractGeoIPDatabase(string $tarFile): void
    {
        // برای استخراج نیاز به PharData داریم
        $phar = new \PharData($tarFile);
        $phar->extractTo($this->databasePath, null, true);
        
        // پیدا کردن فایل .mmdb
        $files = glob($this->databasePath . '*/GeoLite2-City.mmdb');
        
        if (!empty($files)) {
            $sourceFile = $files[0];
            $destFile = $this->databasePath . 'GeoLite2-City.mmdb';
            
            // کپی فایل به مسیر اصلی
            copy($sourceFile, $destFile);
            
            // حذف دایرکتوری موقت
            $tempDir = dirname($sourceFile);
            $this->removeDirectory($tempDir);
        }
    }
    
    /**
     * حذف دایرکتوری به صورت بازگشتی
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        
        rmdir($dir);
    }

    public function check(string $ip): array
    {
        $score = 0;
        $reasons = [];
        $details = [];

        if ($this->isPrivateIP($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.private_range_points', 50);
            $reasons[] = 'استفاده از IP خصوصی';
            $details['is_private'] = true;
        }

        if ($this->isSuspiciousRange($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.suspicious_range_points', 30);
            $reasons[] = 'محدوده IP مشکوک (Datacenter/VPN)';
            $details['suspicious_range'] = true;
        }

        $location = $this->lookup($ip);
        $locationSource = $location['source'] ?? '';
        if (($locationSource === 'default' || $locationSource === 'unknown') && !$this->isPrivateIP($ip) && $ip !== '') {
            $score += $this->policy->getInt('fraud', 'ip.unidentified_points', 40);
            $reasons[] = 'عدم شناسایی موقعیت جغرافیایی IP (نامشخص)';
            $details['unidentified'] = true;
        }

        if ($this->model->isTorNode($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.tor_points', 80);
            $reasons[] = 'استفاده از شبکه Tor';
            $details['is_tor'] = true;
        }

        $userCount = $this->model->getUserCountByIp($ip);
        if ($userCount > $this->policy->getInt('fraud', 'ip.shared_ip_user_threshold', 5)) {
            $score += $this->policy->getInt('fraud', 'ip.shared_ip_points', 40);
            $reasons[] = "استفاده مشترک توسط {$userCount} کاربر";
            $details['user_count'] = $userCount;
        }

        if ($this->model->getIpVelocity($ip)) {
            $score += 25;
            $reasons[] = 'الگوی سرعت تغییر IP مشکوک است';
        }

        $score = min($score, 100);

        return [
            'score' => $score,
            'is_suspicious' => $score >= 60,
            'reasons' => $reasons,
            'details' => $details,
        ];
    }

    private function isSuspiciousRange(string $ip): bool
    {
        $ranges = $this->model->getSuspiciousIpRanges();
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, (string)$range->ip_range)) return true;
        }
        return false;
    }

    

    public function getGeolocation(string $ip): ?array
    {
        if ($this->isPrivateIP($ip)) return null;
        try {
            return $this->lookup($ip);
        } catch (\Throwable $e) {
            $this->logger->error('antifraud.geoip_lookup.failed', ['error' => $e->getMessage()]);
            return ['country_code' => 'XX', 'country' => 'XX', 'city' => 'Unknown'];
        }
    }

    public function blacklistIP(string $ip, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        $this->model->blacklistIp($ip, $reason, $expiresAt);
    }

    public function logIPCheck(int $userId, string $ip, array $checkResult): void
    {
        if ($checkResult['is_suspicious']) {
            $this->model->logFraudEvent([
                'user_id' => $userId,
                'ip' => $ip,
                'score' => (int)$checkResult['score'],
                'type' => 'ip_suspicious',
                'details' => $checkResult
            ]);
        }
    }
}


