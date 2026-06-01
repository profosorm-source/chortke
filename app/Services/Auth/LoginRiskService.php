<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Services\Settings\AppSettings;

/**
 * LoginRiskService — سرویس تشخیص ریسک لاگین
 *
 * بر اساس تعداد تلاش‌های ناموفق و IP، نوع کپچا تعیین می‌شود:
 *
 *  ریسک ۰  (0  تلاش)  → بدون کپچا
 *  ریسک ۱  (1-2 تلاش) → math
 *  ریسک ۲  (3  تلاش)  → image
 *  ریسک ۳  (4+ تلاش)  → recaptcha_v2
 */
class LoginRiskService
{
    public const SCORE_LOW_RISK = 30;
    public const SCORE_MEDIUM_RISK = 60;

    public const FAIL_LIMIT_1 = 1;
    public const FAIL_LIMIT_2 = 2;
    public const FAIL_LIMIT_3 = 3;
    public const FAIL_LIMIT_4 = 4;


    private AppSettings $appSettings;
private \Core\Redis $redis;
private \Core\Cache $cache;
private \App\Contracts\LoggerInterface $logger;
public function __construct(
        \Core\Redis $redis,
        \Core\Cache $cache,
        \App\Contracts\LoggerInterface $logger,
        AppSettings $appSettings
    )
    {    $this->redis = $redis;
    $this->cache = $cache;
    $this->logger = $logger;

        
        $this->appSettings = $appSettings;
        }

    /**
     * محاسبه نوع کپچا بر اساس ریسک
     * null = بدون کپچا لازم نیست
     *
     * ثبت‌نام: همیشه حداقل math — با افزایش خطا سخت‌تر می‌شود
     * ورود: بر اساس تعداد تلاش ناموفق
     */
    public function getCaptchaType(string $context = 'login', ?string $ip = null, ?string $identifier = null): ?string
    {
        // Input validation
        if (empty($context) || strlen($context) > 50) {
            throw new \InvalidArgumentException('Invalid context: must be non-empty and max 50 chars');
        }

        if (!in_array($context, ['login', 'register', 'password_reset'], true)) {
            throw new \InvalidArgumentException('Invalid context: must be login, register, or password_reset');
        }

        if ($ip !== null && strlen($ip) > 45) {
            throw new \InvalidArgumentException('Invalid IP address');
        }

        $resolvedIp = $this->resolveIp($ip);

        // Check if both Redis and Cache are down
        $redis = $this->redis;
        $redisAvailable = false;
        try {
            $redisAvailable = $redis && $redis->isAvailable();
        } catch (\Throwable $e) {}

        $cacheAvailable = true;
        try {
            $this->cache->get('connectivity_test');
        } catch (\Throwable $e) {
            $cacheAvailable = false;
        }

        if (!$redisAvailable && !$cacheAvailable) {
            $this->logger->critical('login_risk.cache_and_redis_down.fail_closed_strict_captcha', [
                'context' => $context,
                'ip' => $resolvedIp
            ]);
            return 'recaptcha_v2';
        }

        try {
            $score = $this->getRiskScore($context, $resolvedIp, $identifier);
        } catch (\Throwable $e) {
            // Fail closed on any score retrieval exception
            $this->logger->error('login_risk.get_score_failed.fail_closed_strict_captcha', [
                'error' => $e->getMessage()
            ]);
            return 'recaptcha_v2';
        }

        if ($context === 'register') {
            $captchaType = $this->determineCaptchaTypeByScore($score);
        } else {
            if ($score === 0) {
                return null;
            }
            $captchaType = $this->determineCaptchaTypeByScore($score);
        }

        $this->logger->info('captcha.required', [
            'context' => $context,
            'score' => $score,
            'captcha_type' => $captchaType,
            'ip' => $resolvedIp
        ]);

        return $captchaType;
    }

    /**
     * تعیین نوع کپچا بر اساس امتیاز ریسک بدون کدهای تکراری
     */
    private function determineCaptchaTypeByScore(int $score): string
    {
        if ($score <= self::SCORE_LOW_RISK) {
            return 'math';
        }
        if ($score <= self::SCORE_MEDIUM_RISK) {
            return 'image';
        }
        return 'recaptcha_v2';
    }

    /**
     * محاسبه امتیاز ریسک (0-100)
     */
    public function getRiskScore(string $context = 'login', ?string $ip = null, ?string $identifier = null): int
    {
        $resolvedIp = $this->resolveIp($ip);
        $failCount = $this->getFailCount($context, $resolvedIp, $identifier);

        $score = 0;

        // 🔒 Dynamic System Tuning: Load failure limits and risk increments from application settings
        // M29 Fix: ارتقا به استفاده از سرویس تنظیمات تزریق‌شده به جای تابع کمکی گلوبال
        $limit1 = (int)$this->appSettings->get('login_risk_limit_1', self::FAIL_LIMIT_1);
        $limit2 = (int)$this->appSettings->get('login_risk_limit_2', self::FAIL_LIMIT_2);
        $limit3 = (int)$this->appSettings->get('login_risk_limit_3', self::FAIL_LIMIT_3);
        $limit4 = (int)$this->appSettings->get('login_risk_limit_4', self::FAIL_LIMIT_4);

        $score1 = (int)$this->appSettings->get('login_risk_score_1', 25);
        $score2 = (int)$this->appSettings->get('login_risk_score_2', 40);
        $score3 = (int)$this->appSettings->get('login_risk_score_3', 65);
        $score4 = (int)$this->appSettings->get('login_risk_score_4', 85);

        if ($failCount === $limit1) {
            $score = $score1;
        } elseif ($failCount === $limit2) {
            $score = $score2;
        } elseif ($failCount === $limit3) {
            $score = $score3;
        } elseif ($failCount >= $limit4) {
            $score = $score4;
        }

        return min(100, $score);
    }

    /**
     * ثبت تلاش ناموفق
     */
    public function recordFailure(string $context = 'login', ?string $ip = null, ?string $identifier = null): void
    {
        $resolvedIp = $this->resolveIp($ip);
        $windowSeconds = $this->getWindowSeconds();
        $windowMinutes = (int)ceil($windowSeconds / 60);

        // 🛡️ MEDIUM-M-07 Fix: Record failure for both IP and Identifier separately
        $keys = [$this->buildKey($context, $resolvedIp, null)];
        if ($identifier) {
            $keys[] = $this->buildKey($context, 'all_ips', $identifier);
        }

        $redis = $this->redis;
        $redisAvailable = false;
        try {
            $redisAvailable = $redis && $redis->isAvailable();
        } catch (\Throwable $e) {}

        foreach ($keys as $key) {
            if ($redisAvailable) {
                try {
                    $raw = $redis->get($key);
                    $data = $this->decodeSignedPayload($raw);
                    if (!$data || !is_array($data) || (time() - ($data['first_at'] ?? 0)) > $windowSeconds) {
                        $data = ['count' => 0, 'first_at' => time()];
                    }
                    $data['count']++;
                    $data['last_at'] = time();
                    $jsonData = json_encode($data);
                    $mac = hash_hmac('sha256', $jsonData, secure_key());
                    $redis->set($key, $mac . '|' . $jsonData, $windowSeconds);
                    continue;
                } catch (\Throwable $e) {
                    $this->logger->warning('login_risk.redis_write_failed', ['error' => $e->getMessage()]);
                }
            }

            try {
                $data = $this->cache->get($key);
                if (!$data || !is_array($data) || (time() - ($data['first_at'] ?? 0)) > $windowSeconds) {
                    $data = ['count' => 0, 'first_at' => time()];
                }

                $data['count']++;
                $data['last_at'] = time();
                $this->cache->put($key, $data, $windowMinutes);
            } catch (\Throwable $e) {
                $this->logger->critical('login_risk.cache_write_failed_in_record_failure', [
                    'key' => $key,
                    'error' => $e->getMessage()
                ]);
            }
        }

        try {
            $currentCount = $this->getFailCount($context, $resolvedIp, $identifier);
            
            // لاگ تلاش ناموفق
            $logLevel = $currentCount >= 4 ? 'warning' : 'info';
            $this->logger->{$logLevel}('login.failure.recorded', [
                'context' => $context,
                'ip' => $resolvedIp,
                'identifier' => $identifier,
                'max_fail_count' => $currentCount
            ]);
            
            // هشدار برای تلاش‌های مشکوک
            if ($currentCount >= 5) {
                $this->logger->critical('login.suspicious.activity', [
                    'context' => $context,
                    'ip' => $resolvedIp,
                    'identifier' => $identifier,
                    'fail_count' => $currentCount
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->critical('login_risk.get_fail_count_failed_in_record_failure', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * پاک کردن سابقه تلاش (بعد از لاگین موفق)
     */
    public function clearFailures(string $context = 'login', ?string $ip = null, ?string $identifier = null): void
    {
        $resolvedIp = $this->resolveIp($ip);
        $ipKey = $this->buildKey($context, $resolvedIp, null);
        $idKey = $identifier ? $this->buildKey($context, 'all_ips', $identifier) : null;

        $redis = $this->redis;
        if ($redis && $redis->isAvailable()) {
            try {
                $redis->delete($ipKey);
                if ($idKey) {
                    $redis->delete($idKey);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('login_risk.redis_clear_failed', ['error' => $e->getMessage()]);
            }
        }

        $this->cache->forget($ipKey);
        if ($idKey) {
            $this->cache->forget($idKey);
        }
    }

    /**
     * تعداد تلاش‌های ناموفق فعلی
     */
    public function getFailCount(string $context = 'login', ?string $ip = null, ?string $identifier = null): int
    {
        $resolvedIp = $this->resolveIp($ip);
        $windowSeconds = $this->getWindowSeconds();

        $redis = $this->redis;
        $redisAvailable = false;
        try {
            $redisAvailable = $redis && $redis->isAvailable();
        } catch (\Throwable $e) {}

        // 🛡️ MEDIUM-M-07 Fix: Aggregating risk from both IP and Identifier
        $ipKey = $this->buildKey($context, $resolvedIp, null);
        $ipCount = 0;
        
        if ($redisAvailable) {
            try {
                $raw = $redis->get($ipKey);
                $ipData = $this->decodeSignedPayload($raw);
                $ipCount = $this->extractValidCount($ipData, $windowSeconds);
            } catch (\Throwable $e) {
                $redisAvailable = false;
            }
        }
        if (!$redisAvailable) {
            try {
                $ipData = $this->cache->get($ipKey);
                $ipCount = $this->extractValidCount($ipData, $windowSeconds);
            } catch (\Throwable $e) {
                $ipCount = 0;
            }
        }

        $idCount = 0;
        if ($identifier) {
            $idKey = $this->buildKey($context, 'all_ips', $identifier);
            if ($redisAvailable) {
                try {
                    $raw = $redis->get($idKey);
                    $idData = $this->decodeSignedPayload($raw);
                    $idCount = $this->extractValidCount($idData, $windowSeconds);
                } catch (\Throwable $e) {
                    $redisAvailable = false;
                }
            }
            if (!$redisAvailable) {
                try {
                    $idData = $this->cache->get($idKey);
                    $idCount = $this->extractValidCount($idData, $windowSeconds);
                } catch (\Throwable $e) {
                    $idCount = 0;
                }
            }
        }

        // MEDIUM-M-01 Fix: Use max instead of sum to avoid double-counting the same attempts
        return max($ipCount, $idCount);
    }

    private function extractValidCount($data, int $windowSeconds): int
    {
        if (!$data || !is_array($data)) return 0;
        if ((time() - ($data['first_at'] ?? 0)) > $windowSeconds) return 0;
        return (int)($data['count'] ?? 0);
    }

    private function decodeSignedPayload(?string $raw): ?array
    {
        if (!$raw || !is_string($raw)) {
            return null;
        }

        if (strpos($raw, '|') === false) {
            return json_decode($raw, true) ?: null;
        }

        [$mac, $jsonData] = explode('|', $raw, 2);
        if (!hash_equals(hash_hmac('sha256', $jsonData, secure_key()), $mac)) {
            $this->logger->critical('login_risk.cache_tampered', ['raw' => substr($raw, 0, 128)]);
            return null;
        }

        return json_decode($jsonData, true) ?: null;
    }

    private function buildKey(string $context, string $ip, ?string $identifier = null): string
    {
        // LOW-05 Fix: Key generation depends on a dedicated risk cache key for better isolation.
        // Falls back to secure_key() if not configured.
        $salt = (string)config('auth.risk_cache_key', secure_key());
        
        if ($identifier) {
            $idHash = hash_hmac('sha256', strtolower(trim($identifier)), $salt);
            if ($ip === 'all_ips') {
                return "login_risk_{$context}_id_{$idHash}";
            }
            $ipHash = hash_hmac('sha256', $ip, $salt);
            return "login_risk_{$context}_ip_{$ipHash}_id_{$idHash}";
        }
        
        $ipHash = hash_hmac('sha256', $ip, $salt);
        return "login_risk_{$context}_ip_{$ipHash}";
    }

    /**
     * M-SRV-06 Fix: دریافت پویا و داینامیک طول پنجره زمانی بررسی حملات لاگین از تنظیمات سامانه
     */
    private function getWindowSeconds(): int
    {
        return (int)$this->appSettings->get('login_risk_window_seconds', 1800);
    }

    /**
     * حل چالش IP Spoofing به صورت مستقل، امن و غیرقابل جعل بدون وابستگی به توابع خارجی
     */
    private function resolveIp(?string $ip = null): string
    {
        $clientIp = ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : get_client_ip();
        $clientIp = filter_var($clientIp, FILTER_VALIDATE_IP) ? $clientIp : '127.0.0.1';
        
        // MED-02 Fix: Normalize IP (IPv6 /64) to prevent subnet-based risk bypass
        return $this->normalizeIp($clientIp);
    }

    private function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            if ($packed === false) return $ip;
            $packed = substr($packed, 0, 8) . str_repeat("\x00", 8); // /64 mask
            return inet_ntop($packed);
        }
        return $ip;
    }
}

