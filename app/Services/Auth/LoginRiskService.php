<?php

namespace App\Services\Auth;

use Core\Session;
use App\Contracts\LoggerInterface;
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
    private Session $session;
    // کلید session برای شمارش تلاش‌های ناموفق هر IP
    private const SESSION_KEY = 'login_fail_count';
    private const SESSION_IP_KEY = 'login_fail_ip';

    public function __construct(Session $session, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->session = $session;
    }

    /**
     * محاسبه نوع کپچا بر اساس ریسک
     * null = بدون کپچا لازم نیست
     *
     * ثبت‌نام: همیشه حداقل math — با افزایش خطا سخت‌تر می‌شود
     * ورود: بر اساس تعداد تلاش ناموفق
     */
    public function getCaptchaType(string $context = 'login'): ?string
    {
        $score = $this->getRiskScore($context);
        $ip = get_client_ip();

        // ثبت‌نام همیشه کپچا دارد (حداقل math)
        if ($context === 'register') {
            if ($score <= 30) {
                $captchaType = 'math';
            } elseif ($score <= 60) {
                $captchaType = 'image';
            } else {
                $captchaType = 'recaptcha_v2';
            }
            
            $this->logger->info('captcha.required', [
                'context' => $context,
                'score' => $score,
                'captcha_type' => $captchaType,
                'ip' => $ip
            ]);
            
            return $captchaType;
        }

        // ورود: بر اساس ریسک
        if ($score === 0) {
            return null;
        } elseif ($score <= 30) {
            $captchaType = 'math';
        } elseif ($score <= 60) {
            $captchaType = 'image';
        } else {
            $captchaType = 'recaptcha_v2';
        }
        
        $this->logger->info('captcha.required', [
            'context' => $context,
            'score' => $score,
            'captcha_type' => $captchaType,
            'ip' => $ip
        ]);
        
        return $captchaType;
    }

    /**
     * محاسبه امتیاز ریسک (0-100)
     */
    public function getRiskScore(string $context = 'login'): int
    {
        $ip = get_client_ip();
        $failCount = $this->getFailCount($context, $ip);

        $score = 0;

        // بر اساس تعداد تلاش ناموفق
        if ($failCount === 1) {
            $score = 25;
        } elseif ($failCount === 2) {
            $score = 40;
        } elseif ($failCount === 3) {
            $score = 65;
        } elseif ($failCount >= 4) {
            $score = 85;
        }

        return min(100, $score);
    }

    /**
     * ثبت تلاش ناموفق
     */
    public function recordFailure(string $context = 'login'): void
    {
        $ip = get_client_ip();
        $key = $this->buildKey($context, $ip);

        $data = $this->session->get($key) ?? ['count' => 0, 'first_at' => time()];

        // اگر بیشتر از ۳۰ دقیقه گذشته، ریست کن
        if ((time() - ($data['first_at'] ?? 0)) > 1800) {
            $data = ['count' => 0, 'first_at' => time()];
        }

        $data['count']++;
        $data['last_at'] = time();
        $this->session->set($key, $data);
        
        // لاگ تلاش ناموفق
        $logLevel = $data['count'] >= 4 ? 'warning' : 'info';
        $this->logger->{$logLevel}('login.failure.recorded', [
            'context' => $context,
            'ip' => $ip,
            'fail_count' => $data['count'],
            'first_at' => date('Y-m-d H:i:s', $data['first_at'])
        ]);
        
        // هشدار برای تلاش‌های مشکوک
        if ($data['count'] >= 5) {
            $this->logger->critical('login.suspicious.activity', [
                'context' => $context,
                'ip' => $ip,
                'fail_count' => $data['count'],
                'duration_minutes' => round((time() - $data['first_at']) / 60, 2)
            ]);
        }
    }

    /**
     * پاک کردن سابقه تلاش (بعد از لاگین موفق)
     */
    public function clearFailures(string $context = 'login'): void
    {
        $ip = get_client_ip();
        $key = $this->buildKey($context, $ip);
        
        $data = $this->session->get($key);
        if ($data && isset($data['count'])) {
            $this->logger->info('login.failures.cleared', [
                'context' => $context,
                'ip' => $ip,
                'previous_fail_count' => $data['count']
            ]);
        }
        
        $this->session->delete($key);
    }

    /**
     * تعداد تلاش‌های ناموفق فعلی
     */
    public function getFailCount(string $context = 'login', ?string $ip = null): int
    {
        $ip  = $ip ?? get_client_ip();
        $key = $this->buildKey($context, $ip);
        $data = $this->session->get($key);

        if (!$data || !is_array($data)) {
            return 0;
        }

        // اگر بیشتر از ۳۰ دقیقه گذشته، صفر حساب کن
        if ((time() - ($data['first_at'] ?? 0)) > 1800) {
            return 0;
        }

        return (int)($data['count'] ?? 0);
    }

    private function buildKey(string $context, string $ip): string
    {
        return "login_risk_{$context}_" . md5($ip);
    }
}
