<?php

declare(strict_types=1);
namespace Core;

class Session
{
    private static ?Session $instance = null;
    private bool $started = false;
    private bool $isStarting = false;
    private string $fingerprint;
    private ?array $oldInputCache = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Ensure session is started (call this before accessing $_SESSION)
     */
    public function ensureStarted(): void
    {
        $this->start();
    }

    /**
     * شروع امن Session (تنها نقطه session_start)
     */
    public function start(): void
    {
        if ($this->started || $this->isStarting || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $this->isStarting = true;
        try {
            $config = config('session');

            // Set Redis session handler
            $handler = new \Core\RedisSessionHandler();
            session_set_save_handler($handler, true);

        session_name($config['name']);

        // H12 Fix: جلوگیری از ست شدن نامعتبر دامین در localhost و محافظت در برابر پارس نادرست
        $host = parse_url(config('app.url', ''), PHP_URL_HOST);
        $cookieDomain = $host && $host !== 'localhost' ? $host : '';

        session_set_cookie_params([
            'lifetime' => $config['lifetime'],
            'path'     => '/',
            'domain'   => $cookieDomain,
            'secure'   => $config['secure'],
            'httponly' => $config['httponly'],
            'samesite' => $config['samesite'],
        ]);

        session_start();
        $this->started = true;

        if (!isset($_SESSION['_initiated'])) {
            $_SESSION['_initiated'] = true;
        }

        $this->validateFingerprint();
        } finally {
            $this->isStarting = false;
        }
    }

    /* -------------------------
     | Basic Session Methods
     * -------------------------*/

    public function set(string $key, $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }
public function delete(string $key): void
{
    $this->ensureStarted();
    unset($_SESSION[$key]);
}
    /* -------------------------
     | Flash Messages
     * -------------------------*/

    public function setFlash(string $key, $value): void
    {
        $this->ensureStarted();
        $_SESSION['__flash'][$key] = $value;
    }

    public function getFlash(string $key)
    {
        $this->ensureStarted();
        if (!isset($_SESSION['__flash'][$key])) {
            return null;
        }

        $value = $_SESSION['__flash'][$key];
        unset($_SESSION['__flash'][$key]);

        return $value;
    }

    public function hasFlash(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION['__flash'][$key]);
    }

    public function flashInput(array $data): void
    {
        $this->ensureStarted();
        foreach ($data as $key => $value) {
            $this->setFlash('old_' . $key, $value);
        }
    }

    /**
     * فلاش کردن خودکار ورودی‌های فعلی POST به درخواست بعدی (بدون مقادیر حساس)
     */
    public function flashOld(): void
    {
        $this->ensureStarted();
        $data = $_POST;
        
        // ایمن‌سازی: حذف پسوردها و توکن CSRF جهت افزایش امنیت
        unset($data['_token'], $data['csrf_token'], $data['password'], $data['password_confirmation'], $data['old_password']);
        
        $this->setFlash('old', $data);
    }

    /**
     * دریافت هوشمند و کش‌شده‌ی ورودی‌های قدیمی
     */
    public function getOld(string $key, $default = null)
    {
        if ($this->oldInputCache === null) {
            $this->ensureStarted();
            // استخراج یک‌بار برای همیشه در طول عمر پردازش و کش موضعی (Atomic cache)
            if (isset($_SESSION['__flash']['old'])) {
                $this->oldInputCache = $_SESSION['__flash']['old'];
                unset($_SESSION['__flash']['old']);
            } else {
                $this->oldInputCache = [];
            }
        }

        return $this->oldInputCache[$key] ?? $default;
    }


private function invalidateSession(): void
{
    // کاربر عملاً logout می‌شود
    $_SESSION = [];

    if (\session_status() === PHP_SESSION_ACTIVE) {
        \session_regenerate_id(true);
    }
}
    /* -------------------------
     | Security
     * -------------------------*/

    private function validateFingerprint(): void
{
    $current = $this->generateFingerprint();

    if (!isset($_SESSION['_fingerprint'])) {
        $_SESSION['_fingerprint'] = $current;
        return;
    }

    if (!\hash_equals((string)$_SESSION['_fingerprint'], (string)$current)) {
        // لاگ امنیتی با جزئیات بیشتر
        if (function_exists('logger')) {
    try {
        logger()->warning('Session fingerprint mismatch - Session will be invalidated', [
                'old_fingerprint' => substr((string)($_SESSION['_fingerprint'] ?? ''), 0, 8) . '...',
                'new_fingerprint' => substr((string)$current, 0, 8) . '...',
                'user_id' => $_SESSION['user_id'] ?? 'guest',
                'user_role' => $_SESSION['user_role'] ?? 'none',
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            ]);
			 } catch (\Throwable $ignore) {}
        }

        // ✅ به جای throw کردن: سشن را باطل کن و ادامه بده
        $this->invalidateSession();
        $_SESSION['_fingerprint'] = $this->generateFingerprint();
        return;
    }
}

    private function generateFingerprint(): string
    {
        // FIX C-5: قبلاً فقط از IP استفاده می‌شد که دو مشکل داشت:
        // ۱. کاربران پشت NAT (شرکت، دفتر) IP یکسان دارند — fingerprint تکراری
        // ۲. کاربران موبایل IP تغییر می‌دهند — session باطل می‌شد
        //
        // راه‌حل: ترکیب IP + User-Agent hash.
        // User-Agent در طول یک session ثابت است اما بین دستگاه‌ها متفاوت.
        // IP را با /24 subnet mask می‌گیریم تا تغییرات جزئی موبایل مشکل نسازد.
        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        // برای IPv4: فقط سه اکتت اول (subnet /24) تا تغییر IP موبایل tolerate شود
        // برای IPv6: 48 بیت اول
        $ipMasked = $ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts    = explode('.', $ip);
            $ipMasked = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // فقط prefix را نگه می‌داریم
            $ipMasked = substr($ip, 0, strrpos($ip, ':') ?: strlen($ip));
        }

        return hash('sha256', json_encode([
            'ip_subnet'  => $ipMasked,
            'user_agent' => $userAgent,
        ]));
    }

    /* -------------------------
     | Lifecycle
     * -------------------------*/

    public function regenerate(): void
    {
        // M15 Fix: تضمین فعالیت سشن و بازنویسی اثرانگشت امنیتی به صورت همزمان با تغییر شناسه کاربری
        $this->ensureStarted();
        session_regenerate_id(true);
        $_SESSION['_fingerprint'] = $this->generateFingerprint();
    }

    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    public function getId(): string
    {
        return session_id();
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}