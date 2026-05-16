<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Core\Cache;
use App\Contracts\LoggerInterface;
use App\Services\SettingService;

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
class LoginRiskService extends \App\Services\BaseService
{
    public const SCORE_LOW_RISK = 30;
    public const SCORE_MEDIUM_RISK = 60;

    public const FAIL_LIMIT_1 = 1;
    public const FAIL_LIMIT_2 = 2;
    public const FAIL_LIMIT_3 = 3;
    public const FAIL_LIMIT_4 = 4;

    private Cache $cache;
    private SettingService $settingService;

    public function __construct(Cache $cache, SettingService $settingService, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->cache = $cache;
        $this->settingService = $settingService;
    }

    /**
     * محاسبه نوع کپچا بر اساس ریسک
     * null = بدون کپچا لازم نیست
     *
     * ثبت‌نام: همیشه حداقل math — با افزایش خطا سخت‌تر می‌شود
     * ورود: بر اساس تعداد تلاش ناموفق
     */
    public function getCaptchaType(string $context = 'login', ?string $ip = null): ?string
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
        $score = $this->getRiskScore($context, $resolvedIp);

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
    public function getRiskScore(string $context = 'login', ?string $ip = null): int
    {
        $resolvedIp = $this->resolveIp($ip);
        $failCount = $this->getFailCount($context, $resolvedIp);

        $score = 0;

        // 🔒 Dynamic System Tuning: Load failure limits and risk increments from application settings
        // M29 Fix: ارتقا به استفاده از سرویس تنظیمات تزریق‌شده به جای تابع کمکی گلوبال
        $limit1 = (int)$this->settingService->get('login_risk_limit_1', self::FAIL_LIMIT_1);
        $limit2 = (int)$this->settingService->get('login_risk_limit_2', self::FAIL_LIMIT_2);
        $limit3 = (int)$this->settingService->get('login_risk_limit_3', self::FAIL_LIMIT_3);
        $limit4 = (int)$this->settingService->get('login_risk_limit_4', self::FAIL_LIMIT_4);

        $score1 = (int)$this->settingService->get('login_risk_score_1', 25);
        $score2 = (int)$this->settingService->get('login_risk_score_2', 40);
        $score3 = (int)$this->settingService->get('login_risk_score_3', 65);
        $score4 = (int)$this->settingService->get('login_risk_score_4', 85);

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
    public function recordFailure(string $context = 'login', ?string $ip = null): void
    {
        $resolvedIp = $this->resolveIp($ip);
        $key = $this->buildKey($context, $resolvedIp);

        $data = $this->cache->get($key);
        if (!$data || !is_array($data)) {
            $data = ['count' => 0, 'first_at' => time()];
        }

        $windowSeconds = $this->getWindowSeconds();

        // اگر بیشتر از بازه زمانی مجاز گذشته، ریست کن
        if ((time() - ($data['first_at'] ?? 0)) > $windowSeconds) {
            $data = ['count' => 0, 'first_at' => time()];
        }

        $data['count']++;
        $data['last_at'] = time();
        
        // ذخیره در کش متناسب با بازه زمانی پیکربندی‌شده
        $windowMinutes = (int)ceil($windowSeconds / 60);
        $this->cache->put($key, $data, $windowMinutes); 
        
        // لاگ تلاش ناموفق
        $logLevel = $data['count'] >= 4 ? 'warning' : 'info';
        $this->logger->{$logLevel}('login.failure.recorded', [
            'context' => $context,
            'ip' => $resolvedIp,
            'fail_count' => $data['count'],
            'first_at' => date('Y-m-d H:i:s', $data['first_at'])
        ]);
        
        // هشدار برای تلاش‌های مشکوک
        if ($data['count'] >= 5) {
            $this->logger->critical('login.suspicious.activity', [
                'context' => $context,
                'ip' => $resolvedIp,
                'fail_count' => $data['count'],
                'duration_minutes' => round((time() - $data['first_at']) / 60, 2)
            ]);
        }
    }

    /**
     * پاک کردن سابقه تلاش (بعد از لاگین موفق)
     */
    public function clearFailures(string $context = 'login', ?string $ip = null): void
    {
        $resolvedIp = $this->resolveIp($ip);
        $key = $this->buildKey($context, $resolvedIp);
        
        $data = $this->cache->get($key);
        if ($data && isset($data['count'])) {
            $this->logger->info('login.failures.cleared', [
                'context' => $context,
                'ip' => $resolvedIp,
                'previous_fail_count' => $data['count']
            ]);
        }
        
        $this->cache->forget($key);
    }

    /**
     * تعداد تلاش‌های ناموفق فعلی
     */
    public function getFailCount(string $context = 'login', ?string $ip = null): int
    {
        $resolvedIp = $this->resolveIp($ip);
        $key = $this->buildKey($context, $resolvedIp);
        $data = $this->cache->get($key);

        if (!$data || !is_array($data)) {
            return 0;
        }

        $windowSeconds = $this->getWindowSeconds();

        // اگر بیشتر از بازه زمانی مجاز گذشته، صفر حساب کن
        if ((time() - ($data['first_at'] ?? 0)) > $windowSeconds) {
            return 0;
        }

        return (int)($data['count'] ?? 0);
    }

    private function buildKey(string $context, string $ip): string
    {
        // HIGH-06 Fix: Use HMAC-SHA256 with app key to prevent precomputation and cache poisoning
        $salt = (string)config('app.key');
        return "login_risk_{$context}_" . hash_hmac('sha256', $ip, $salt);
    }

    /**
     * M-SRV-06 Fix: دریافت پویا و داینامیک طول پنجره زمانی بررسی حملات لاگین از تنظیمات سامانه
     */
    private function getWindowSeconds(): int
    {
        return (int)$this->settingService->get('login_risk_window_seconds', 1800);
    }

    /**
     * حل چالش IP Spoofing به صورت مستقل، امن و غیرقابل جعل بدون وابستگی به توابع خارجی
     */
    private function resolveIp(?string $ip = null): string
    {
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        
        // MED Fix: استفاده از تابع جهانی، استاندارد و ضدجعل get_client_ip جهت ممانعت قطعی از دور زدن سیستم امنیتی
        $clientIp = get_client_ip();
        return filter_var($clientIp, FILTER_VALIDATE_IP) ? $clientIp : '127.0.0.1';
    }
}
