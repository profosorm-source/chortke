<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\IpAndDeviceModel;
use App\Services\AntiFraud\RiskPolicyService;
use App\Contracts\LoggerInterface;
/**
 * GeolocationIntelligenceService
 * 
 * تحلیل جغرافیایی پیشرفته و تشخیص سفرهای غیرممکن
 */
class GeolocationIntelligenceService
{
    private IpAndDeviceModel $model;
    private RiskPolicyService $policy;
    private const COUNTRY_RISK_SCORES = [
        'IR' => 10,
        'US' => 15,
        'GB' => 15,
        'DE' => 20,
        'CN' => 40,
        'RU' => 45,
        'KP' => 90,
        'CU' => 80,
    ];
    
    private const MAX_TRAVEL_SPEED_KMH = 900;

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        IpAndDeviceModel $model,
        RiskPolicyService $policy
    ) {        $this->logger = $logger;

                $this->model = $model;
        $this->policy = $policy;
    }

    /**
     * تشخیص سفر غیرممکن (Impossible Travel)
     */
    public function detectImpossibleTravel(
        int $userId,
        string $currentIp,
        array $currentLocation
    ): array {
        $lastLogin = $this->getLastLogin($userId);
        
        if (!$lastLogin) {
            // HIGH-01: Fallback to initial registration baseline if standard last-login is absent
            $lastLogin = $this->getRegistrationBaseline($userId);
            if (!$lastLogin) {
                return [
                    'is_impossible' => false,
                    'reason' => 'اولین لاگین یا داده کافی وجود ندارد'
                ];
            }
        }
        
        $distance = $this->calculateDistance(
            (float)$lastLogin['latitude'],
            (float)$lastLogin['longitude'],
            (float)$currentLocation['latitude'],
            (float)$currentLocation['longitude']
        );
        
        $timeDiffSeconds = time() - strtotime((string)$lastLogin['login_at']);
        $timeDiffHours = $timeDiffSeconds / 3600;
        
        $requiredSpeed = $timeDiffHours > 0 ? ($distance / $timeDiffHours) : PHP_FLOAT_MAX;
        
        $maxSpeed = $this->policy->getInt('fraud', 'geo.max_travel_speed_kmh', self::MAX_TRAVEL_SPEED_KMH);
        
        $isImpossible = $requiredSpeed > $maxSpeed;
        
        if ($isImpossible) {
            $this->logImpossibleTravel($userId, [
                'previous_location' => [
                    'country' => $lastLogin['country'],
                    'city' => $lastLogin['city'],
                    'ip' => $lastLogin['ip_address']
                ],
                'current_location' => [
                    'country' => $currentLocation['country'],
                    'city' => $currentLocation['city'],
                    'ip' => $currentIp
                ],
                'distance_km' => round($distance, 2),
                'time_diff_hours' => round($timeDiffHours, 2),
                'required_speed_kmh' => round($requiredSpeed, 2),
                'max_allowed_speed_kmh' => $maxSpeed
            ]);
        }
        
        return [
            'is_impossible' => $isImpossible,
            'distance_km' => round($distance, 2),
            'time_diff_hours' => round($timeDiffHours, 2),
            'required_speed_kmh' => round($requiredSpeed, 2),
            'max_allowed_speed_kmh' => $maxSpeed,
            'risk_score' => $isImpossible ? 90 : 0,
            'previous_location' => [
                'country' => $lastLogin['country'],
                'city' => $lastLogin['city'],
                'ip' => $lastLogin['ip_address']
            ],
            'current_location' => $currentLocation
        ];
    }

    private function calculateDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371;
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }

    private function getLastLogin(int $userId): ?array
    {
        $result = $this->model->getLastLoginLocation($userId);
        return $result ? (array)$result : null;
    }

    /**
     * Establishes user initial registration footprint mapping to prevent spoofing across logins.
     */
    private function getRegistrationBaseline(int $userId): ?array
    {
        try {
            // Resolves earliest established physical geographic anchor
            $baseline = $this->model->fetch(
                "SELECT ip_address, country, city, latitude, longitude, created_at as login_at 
                 FROM user_sessions WHERE user_id = ? AND latitude IS NOT NULL 
                 ORDER BY created_at ASC LIMIT 1",
                [$userId]
            );
            return $baseline ? (array)$baseline : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * امتیازدهی ریسک بر اساس کشور
     */
    public function getCountryRiskScore(string $countryCode): int
    {
        $customScore = $this->policy->getInt('fraud', "geo.country_risk.{$countryCode}", null);
        
        if ($customScore !== null) {
            return $customScore;
        }
        
        // MED-03: Dynamically load global Country Risk Mapping array from Settings Policy
        $riskMap = $this->policy->getArray('fraud', 'geo.country_risk_scores', self::COUNTRY_RISK_SCORES);

        return (int)($riskMap[$countryCode] ?? 30);
    }

    /**
     * تحلیل سرعت جغرافیایی (Geo-Velocity)
     */
    public function analyzeGeoVelocity(int $userId, int $lookbackHours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($lookbackHours * 3600));
        $sessions = $this->model->getSessionsForVelocity($userId, $since);
        
        // HIGH-02: Slice array tracking depth to preserve loop iteration performance
        if (count($sessions) > 25) {
            $sessions = \array_slice($sessions, -25);
        }

        if (count($sessions) < 2) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل وجود ندارد',
                'locations_count' => count($sessions)
            ];
        }
        
        $anomalies = [];
        $totalDistance = 0;
        $maxSpeed = 0;
        $uniqueCountries = [];
        
        for ($i = 1; $i < count($sessions); $i++) {
            $prev = $sessions[$i - 1];
            $curr = $sessions[$i];
            
            $distance = $this->calculateDistance(
                (float)$prev->latitude,
                (float)$prev->longitude,
                (float)$curr->latitude,
                (float)$curr->longitude
            );
            
            $timeDiff = strtotime((string)$curr->created_at) - strtotime((string)$prev->created_at);
            $speed = $timeDiff > 0 ? ($distance / ($timeDiff / 3600)) : 0;
            
            $totalDistance += $distance;
            $maxSpeed = max($maxSpeed, $speed);
            
            $uniqueCountries[$prev->country] = true;
            $uniqueCountries[$curr->country] = true;
            
            if ($speed > self::MAX_TRAVEL_SPEED_KMH) {
                $anomalies[] = [
                    'type' => 'high_speed',
                    'from' => [
                        'country' => $prev->country,
                        'city' => $prev->city,
                        'time' => $prev->created_at
                    ],
                    'to' => [
                        'country' => $curr->country,
                        'city' => $curr->city,
                        'time' => $curr->created_at
                    ],
                    'distance_km' => round($distance, 2),
                    'speed_kmh' => round($speed, 2)
                ];
            }
        }
        
        $countriesCount = count($uniqueCountries);
        $isSuspicious = !empty($anomalies) || $countriesCount > 3;
        
        return [
            'is_suspicious' => $isSuspicious,
            'locations_count' => count($sessions),
            'unique_countries' => $countriesCount,
            'total_distance_km' => round($totalDistance, 2),
            'max_speed_kmh' => round($maxSpeed, 2),
            'anomalies' => $anomalies,
            'risk_score' => $this->calculateVelocityRiskScore($anomalies, $countriesCount)
        ];
    }

    private function calculateVelocityRiskScore(array $anomalies, int $countriesCount): int
    {
        $score = count($anomalies) * 30;
        
        if ($countriesCount > 2) {
            $score += ($countriesCount - 2) * 15;
        }
        
        return min(100, $score);
    }

    /**
     * تشخیص ناهماهنگی Timezone
     */
    public function detectTimezoneAnomaly(
        string $ipTimezone,
        string $browserTimezone
    ): array {
        $ipOffset = $this->getTimezoneOffset($ipTimezone);
        $browserOffset = $this->getTimezoneOffset($browserTimezone);
        
        if ($ipOffset === null || $browserOffset === null) {
            return [
                'is_anomaly' => false,
                'reason' => 'اطلاعات timezone ناقص است'
            ];
        }
        
        $difference = abs($ipOffset - $browserOffset);
        
        // MED-04: Load allowed drift discrepancy from dynamic RiskPolicy store
        $allowedDrift = (float)$this->policy->getFloat('fraud', 'geo.timezone_discrepancy_limit', 2.0);
        $isAnomaly = $difference > $allowedDrift;
        
        return [
            'is_anomaly' => $isAnomaly,
            'ip_timezone' => $ipTimezone,
            'browser_timezone' => $browserTimezone,
            'offset_difference_hours' => $difference,
            'risk_score' => $isAnomaly ? 40 : 0
        ];
    }

    private function getTimezoneOffset(string $timezone): ?float
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $offset = $tz->getOffset(new \DateTime());
            return $offset / 3600;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Geolocation Lookup
     */
    public function lookup(string $ip): ?array
    {
        $cached = $this->getFromCache($ip);
        if ($cached) {
            return $cached;
        }
        
        $location = $this->lookupMaxMind($ip);
        
        if (!$location) {
            $location = $this->lookupIPAPI($ip);
        }
        
        if ($location) {
            $this->saveToCache($ip, $location);
        }
        
        return $location;
    }

    private function lookupMaxMind(string $ip): ?array
    {
        $dbPath = __DIR__ . '/../../../storage/geoip/GeoLite2-City.mmdb';
        
        if (!file_exists($dbPath)) {
            return null;
        }
        
        try {
            $reader = new \GeoIp2\Database\Reader($dbPath);
            $record = $reader->city($ip);
            
            return [
                'country_code' => $record->country->isoCode,
                'country_name' => $record->country->name,
                'city' => $record->city->name,
                'latitude' => $record->location->latitude,
                'longitude' => $record->location->longitude,
                'timezone' => $record->location->timeZone,
                'source' => 'maxmind'
            ];
        } catch (\Exception $e) {
            $this->logger->error('geo.maxmind.failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function lookupIPAPI(string $ip): ?array
    {
        try {
            $url = "http://ip-api.com/json/{$ip}?fields=status,country,countryCode,city,lat,lon,timezone";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            if ($data['status'] !== 'success') {
                return null;
            }
            
            return [
                'country_code' => $data['countryCode'],
                'country_name' => $data['country'],
                'city' => $data['city'],
                'latitude' => $data['lat'],
                'longitude' => $data['lon'],
                'timezone' => $data['timezone'],
                'source' => 'ip-api'
            ];
        } catch (\Exception $e) {
            $this->logger->error('geo.ipapi.failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getFromCache(string $ip): ?array
    {
        $result = $this->model->getFromCache($ip);
        
        if (!$result) {
            return null;
        }
        
        return [
            'country_code' => $result->country_code,
            'country_name' => $result->country_name,
            'city' => $result->city,
            'latitude' => (float)$result->latitude,
            'longitude' => (float)$result->longitude,
            'timezone' => $result->timezone,
            'source' => 'cache'
        ];
    }

    private function saveToCache(string $ip, array $location): void
    {
        $this->model->saveToCache($ip, $location);
    }

    private function logImpossibleTravel(int $userId, array $details): void
    {
        $this->model->logImpossibleTravel($userId, $details);
        
        $this->logger->critical('fraud.impossible_travel.detected', [
            'user_id' => $userId,
            'details' => $details
        ]);
    }

    /**
     * تحلیل جامع جغرافیایی
     */
    public function analyze(int $userId, string $ip, array $browserData = []): array
    {
        $location = $this->lookup($ip);
        $isUnidentified = false;
        
        if (!$location) {
            $isUnidentified = true;
            $location = [
                'country_code' => 'XX',
                'country_name' => 'Unknown',
                'city' => 'Unknown',
                'latitude' => 0.0,
                'longitude' => 0.0,
                'timezone' => 'UTC',
                'source' => 'unknown'
            ];
        }
        
        $unidentifiedRisk = $isUnidentified ? $this->policy->getInt('fraud', 'geo.unidentified_ip_risk', 50) : 0;
        
        $analysis = [
            'location' => $location,
            'country_risk_score' => $isUnidentified ? 0 : $this->getCountryRiskScore($location['country_code']),
            'impossible_travel' => $isUnidentified ? ['risk_score' => 0] : $this->detectImpossibleTravel($userId, $ip, $location),
            'velocity_analysis' => $this->analyzeGeoVelocity($userId, 24),
            'unidentified_ip_risk' => $unidentifiedRisk,
        ];
        
        if (isset($browserData['timezone'])) {
            $analysis['timezone_anomaly'] = $this->detectTimezoneAnomaly(
                $location['timezone'],
                $browserData['timezone']
            );
        }
        
        $totalRisk = $analysis['country_risk_score']
                   + ($analysis['impossible_travel']['risk_score'] ?? 0)
                   + $analysis['velocity_analysis']['risk_score']
                   + $analysis['unidentified_ip_risk']
                   + ($analysis['timezone_anomaly']['risk_score'] ?? 0);
        
        $analysis['total_risk_score'] = min(100, $totalRisk);
        $analysis['is_high_risk'] = $totalRisk >= 70;
        
        return $analysis;
    }

    public function cleanupCache(): int
    {
        return $this->model->cleanupCache();
    }
}

