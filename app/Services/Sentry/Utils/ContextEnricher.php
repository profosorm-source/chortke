<?php

namespace App\Services\Sentry\Utils;

/**
 * 🎨 ContextEnricher - غنی‌سازی Context با اطلاعات محیطی
 * 
 * اطلاعات جمع‌آوری شده:
 * - Device (نوع دستگاه، مدل)
 * - Browser (نام، نسخه)
 * - OS (سیستم‌عامل، نسخه)
 * - Runtime (PHP version, extensions)
 * - App (memory, load)
 */
class ContextEnricher
{
    /**
     * 🎯 Enrich - غنی‌سازی کامل
     */
    public function enrich(): array
    {
        return [
            'device' => $this->getDeviceContext(),
            'browser' => $this->getBrowserContext(),
            'os' => $this->getOSContext(),
            'runtime' => $this->getRuntimeContext(),
            'app' => $this->getAppContext(),
        ];
    }

    /**
     * 📱 Get Device Context
     */
    private function getDeviceContext(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        return [
            'name' => $this->detectDevice($userAgent),
            'family' => $this->detectDeviceFamily($userAgent),
            'model' => $this->detectDeviceModel($userAgent),
            'brand' => $this->detectBrand($userAgent),
            'screen_resolution' => $_SERVER['HTTP_SEC_CH_VIEWPORT_WIDTH'] ?? null,
        ];
    }

    /**
     * 🌐 Get Browser Context
     */
    private function getBrowserContext(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        return [
            'name' => $this->detectBrowser($userAgent),
            'version' => $this->detectBrowserVersion($userAgent),
        ];
    }

    /**
     * 💻 Get OS Context
     */
    private function getOSContext(): array
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        return [
            'name' => $this->detectOS($userAgent),
            'version' => $this->detectOSVersion($userAgent),
            'kernel_version' => php_uname('r'),
        ];
    }

    /**
     * ⚙️ Get Runtime Context
     */
    private function getRuntimeContext(): array
    {
        return [
            'name' => 'php',
            'version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
        ];
    }

    /**
     * 📦 Get App Context
     */
    private function getAppContext(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'memory_limit' => ini_get('memory_limit'),
            'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
        ];
    }

    /**
     * 🔍 Detect Device
     */
    private function detectDevice(string $ua): string
    {
        if (preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua)) {
            return 'Mobile';
        }
        if (preg_match('/Tablet|iPad/i', $ua)) {
            return 'Tablet';
        }
        return 'Desktop';
    }

    /**
     * 📱 Detect Device Family
     */
    private function detectDeviceFamily(string $ua): ?string
    {
        if (preg_match('/iPhone/i', $ua)) return 'iPhone';
        if (preg_match('/iPad/i', $ua)) return 'iPad';
        if (preg_match('/Android/i', $ua)) return 'Android';
        if (preg_match('/Windows Phone/i', $ua)) return 'Windows Phone';
        
        return null;
    }

    /**
     * 🏷️ Detect Device Model
     */
    private function detectDeviceModel(string $ua): ?string
    {
        // iPhone
        if (preg_match('/iPhone OS (\d+_\d+)/', $ua, $matches)) {
            return 'iPhone (iOS ' . str_replace('_', '.', $matches[1]) . ')';
        }
        
        // Android
        if (preg_match('/Android.*?;\s*([^)]+)\s*\)/', $ua, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }

    /**
     * 🏢 Detect Brand
     */
    private function detectBrand(string $ua): ?string
    {
        $brands = [
            '/Apple/i' => 'Apple',
            '/Samsung/i' => 'Samsung',
            '/Huawei/i' => 'Huawei',
            '/Xiaomi/i' => 'Xiaomi',
            '/Nokia/i' => 'Nokia',
            '/LG/i' => 'LG',
            '/Sony/i' => 'Sony',
        ];
        
        foreach ($brands as $pattern => $brand) {
            if (preg_match($pattern, $ua)) {
                return $brand;
            }
        }
        
        return null;
    }

    /**
     * 🌐 Detect Browser
     */
    private function detectBrowser(string $ua): string
    {
        if (preg_match('/Edge/i', $ua)) return 'Edge';
        if (preg_match('/Chrome/i', $ua)) return 'Chrome';
        if (preg_match('/Safari/i', $ua)) return 'Safari';
        if (preg_match('/Firefox/i', $ua)) return 'Firefox';
        if (preg_match('/Opera|OPR/i', $ua)) return 'Opera';
        if (preg_match('/MSIE|Trident/i', $ua)) return 'Internet Explorer';
        
        return 'Unknown';
    }

    /**
     * 🔢 Detect Browser Version
     */
    private function detectBrowserVersion(string $ua): ?string
    {
        $patterns = [
            '/Edge\/(\d+\.\d+)/' => 'Edge',
            '/Chrome\/(\d+\.\d+)/' => 'Chrome',
            '/Version\/(\d+\.\d+).*Safari/' => 'Safari',
            '/Firefox\/(\d+\.\d+)/' => 'Firefox',
            '/OPR\/(\d+\.\d+)/' => 'Opera',
        ];
        
        foreach ($patterns as $pattern => $browser) {
            if (preg_match($pattern, $ua, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }

    /**
     * 💻 Detect OS
     */
    private function detectOS(string $ua): string
    {
        if (preg_match('/Windows/i', $ua)) return 'Windows';
        if (preg_match('/Mac OS X|MacOS/i', $ua)) return 'macOS';
        if (preg_match('/Linux/i', $ua)) return 'Linux';
        if (preg_match('/Android/i', $ua)) return 'Android';
        if (preg_match('/iOS|iPhone|iPad/i', $ua)) return 'iOS';
        
        return 'Unknown';
    }

    /**
     * 🔢 Detect OS Version
     */
    private function detectOSVersion(string $ua): ?string
    {
        // Windows
        if (preg_match('/Windows NT (\d+\.\d+)/', $ua, $matches)) {
            $versions = [
                '10.0' => '10',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
            ];
            return $versions[$matches[1]] ?? $matches[1];
        }
        
        // macOS
        if (preg_match('/Mac OS X (\d+[._]\d+([._]\d+)?)/', $ua, $matches)) {
            return str_replace('_', '.', $matches[1]);
        }
        
        // iOS
        if (preg_match('/OS (\d+_\d+(_\d+)?)/', $ua, $matches)) {
            return str_replace('_', '.', $matches[1]);
        }
        
        // Android
        if (preg_match('/Android (\d+\.\d+)/', $ua, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * 🌍 Get Geolocation (if available)
     */
    public function getGeolocation(): ?array
    {
        // اگر CloudFlare یا load balancer داری
        $country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? null;
        $city = $_SERVER['HTTP_CF_IPCITY'] ?? null;
        
        if ($country || $city) {
            return array_filter([
                'country' => $country,
                'city' => $city,
            ]);
        }
        
        return null;
    }
}
