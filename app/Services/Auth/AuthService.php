<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\User\UserService;
use App\Services\EmailService;
use App\Services\BaseService;
use App\Services\AuditTrail;
use Core\Logger;
use Core\Session;
use Core\RateLimiter;
use Core\EventDispatcher;
use App\Events\UserLoggedInEvent;
use App\Events\UserRegisteredEvent;

/**
 * AuthService
 *
 * هماهنگ‌کننده اصلی احراز هویت.
 */
class AuthService extends \App\Services\BaseService
{
    public function __construct(
        Logger $logger,
        private UserService $userService,
        private User $userModel,
        private SecurityModel $securityModel,
        private Session $session,
        private RateLimiter $rateLimiter,
        private SessionService $sessionService,
        private AuditTrail $auditTrail,
        private TwoFactorService $twoFactorService,
        private \App\Services\SettingService $settingService,
        private EventDispatcher $eventDispatcher,
        private ?EmailService $emailService = null
    ) {
        parent::__construct($logger);
    }

    /**
     * بررسی محدودیت نرخ درخواست برای ورود امن
     */
    public function checkRateLimit(string $action, string $key): void
    {
        $ip = $this->clientIp();
        $rateLimitKey = "{$action}:{$key}:{$ip}";
        $rateLimitCheck = $this->rateLimiter->checkLoginAttempt($rateLimitKey);
        
        // H16 Fix: الگوی امن Fail-Closed؛ اگر خروجی به صراحت TRUE نباشد، درخواست بلاک می‌شود.
        if (!is_array($rateLimitCheck) || !isset($rateLimitCheck['allowed']) || $rateLimitCheck['allowed'] !== true) {
            throw new \Exception($rateLimitCheck['message'] ?? 'تعداد درخواست بیش از حد مجاز است. لطفاً بعداً تلاش کنید.', 429);
        }
    }

    public function login(string $identifier, string $password, bool $remember = false): array
    {
        $rateLimitCheck = $this->rateLimiter->checkLoginAttempt($identifier);
        
        // H16 Fix: الگوی امن Fail-Closed؛ ممانعت از دور زدن نرخ درخواست در لاگین
        if (!is_array($rateLimitCheck) || !isset($rateLimitCheck['allowed']) || $rateLimitCheck['allowed'] !== true) {
            return ['success' => false, 'message' => $rateLimitCheck['message'] ?? 'تعداد تلاش‌های ورود بیش از حد مجاز است.'];
        }

        $user = $this->userModel->findByCredentials($identifier);
        if (!$user || !password_verify($password, $user->password)) {
            $this->logger->warning('auth.login.failed', ['identifier' => $identifier]);
            
            // فقط وقتی تلاش‌ها کمتر از حد آستانه باشد نمره تقلب افزایش می‌یابد
            if ($user && $this->rateLimiter->getAttempts('login:' . $identifier) <= 5) {
                try {
                    $this->userModel->incrementFraudScore((int)$user->id, 5);
                } catch (\Throwable $e) {
                    $this->logError('auth.fraud_score_increment_failed', $e->getMessage());
                }
            }
            
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ($user->status === 'banned' || $user->status === 'suspended') {
            return ['success' => false, 'message' => 'حساب کاربری شما مسدود یا تعلیق شده است.'];
        }

        if (empty($user->email_verified_at)) {
            // L-SRV-05 Fix: ادغام با پیام خطای عمومی جهت ممانعت قطعی از نشت وضعیت حساب (User Enumeration)
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        $this->rateLimiter->clearLoginAttempts($identifier);
        
        // اگر 2FA فعال باشد، session کامل نسازیم
        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            $this->createSession($user, $remember);
        } else {
            $this->createPending2FASession($user);
        }

        // 🚀 UPG-05: پردازش آسنکرون رویداد ورود با الگوی رویدادگرا (Async Event-Driven)
        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, $this->clientIp(), get_user_agent())
        );
        
        return [
            'success'      => true,
            'message'      => 'ورود موفقیت‌آمیز بود.',
            'user'         => [
                'id' => (int)$user->id,
                'username' => $user->username ?? '',
                'role' => $user->role,
                'email' => $user->email ?? null,
            ],
            'requires_2fa' => $requires2FA,
        ];
    }

    /**
     * ورود مستقیم کاربر (برای سیستم‌های احراز هویت مکمل مانند OAuth)
     * H19 Fix: تجمیع فرآیند ساخت Session و توزیع 2FA برای تضمین یکپارچگی ورودهای شخص‌ثالث
     */
    public function loginDirectly(object $user): array
    {
        if ($user->status === 'banned' || $user->status === 'suspended') {
            return ['success' => false, 'message' => 'حساب کاربری شما مسدود یا تعلیق شده است.'];
        }

        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            $this->createSession($user, false);
        } else {
            $this->createPending2FASession($user);
        }

        // 🚀 UPG-05: پردازش آسنکرون رویداد ورود مستقیم (Async Event-Driven)
        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, $this->clientIp(), get_user_agent())
        );

        return [
            'success'      => true,
            'user'         => [
                'id' => (int)$user->id,
                'username' => $user->username ?? '',
                'role' => $user->role,
                'email' => $user->email ?? null,
            ],
            'requires_2fa' => $requires2FA,
        ];
    }

    private function createPending2FASession(object $user): void
    {
        $this->session->regenerate();
        $this->session->set('pending_2fa_user_id', (int)$user->id);
        // logged_in را اینجا ست نکنیم تا bypass نشود
    }

    private function createSession(object $user, bool $remember = false): void
    {
        $this->session->regenerate();
        $this->session->set('user_id',  (int)$user->id);
        $this->session->set('username', $user->username ?? '');
        $this->session->set('role',     $user->role);
        $this->session->set('is_admin', in_array($user->role, ['admin', 'super_admin'], true));
        $this->session->set('logged_in', true);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $this->userModel->update((int)$user->id, ['remember_token' => hash('sha256', $token)]);
            
            $rememberDays = (int)$this->settingService->get('auth_remember_days', 30);
            // 🛡️ Modernized Security Attributes: Strictly enforcing HttpOnly, Secure and Lax SameSite policies
            setcookie('remember_token', $token, [
                'expires' => time() + ($rememberDays * 86400),
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        $this->sessionService->recordSession(
            userId: (int)$user->id,
            sessionId: $this->session->getId(),
            userAgent: get_user_agent(),
            ipAddress: $this->clientIp(),
            acceptLanguage: $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            acceptEncoding: $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        );
    }

    public function logout(): void
    {
        $userId = $this->session->get('user_id');
        if ($userId) {
            $this->logger->activity('auth.logout', 'خروج کاربر', (int)$userId);
        }

        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }

        $this->session->destroy();
    }

    public function verify2FA(string $code): array
    {
        $pendingUserId = $this->session->get('pending_2fa_user_id');
        if (!$pendingUserId) {
            return ['success' => false, 'message' => 'هیچ درخواست 2FA pending وجود ندارد.'];
        }

        $user = $this->userModel->find((int)$pendingUserId);
        if (!$user || !$user->two_factor_enabled) {
            $this->session->destroy();
            return ['success' => false, 'message' => 'کاربر یافت نشد یا 2FA غیرفعال است.'];
        }

        if (!$this->twoFactorService->verifyCode($user->two_factor_secret, $code, (int)$user->id)) {
            $this->logger->activity('auth.2fa_failed', 'کد 2FA نامعتبر', (int)$user->id);
            return ['success' => false, 'message' => 'کد 2FA نامعتبر است.'];
        }

        // پاک کردن pending session و ساخت session اصلی
        $this->session->remove('pending_2fa_user_id');
        $this->createSession($user, false); // remember = false چون قبلاً چک شده

        // 🚀 UPG-05: پردازش آسنکرون رویداد پس از تایید موفق دو عاملی
        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, $this->clientIp(), get_user_agent())
        );

        return [
            'success' => true,
            'message' => 'احراز هویت دو مرحله‌ای تأیید شد.',
            'user' => [
                'id' => (int)$user->id,
                'username' => $user->username ?? '',
                'role' => $user->role,
                'email' => $user->email ?? null,
            ],
        ];
    }

    public function validateRegister(array $data): array
    {
        $validator = new \Core\Validator($data, [
            'full_name' => 'required|min:3',
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        $errors = [];
        if ($validator->fails()) {
            foreach ($validator->errors() as $field => $errs) {
                if (is_array($errs)) {
                    $errors = array_merge($errors, $errs);
                } else {
                    $errors[] = $errs;
                }
            }
        }

        if ($this->userService->emailExists($data['email'])) {
            $errors[] = 'این ایمیل قبلاً ثبت شده است.';
        }

        // H23 Fix: اعمال سیاست پیچیدگی رمز عبور (Password Policy)
        $policyErrors = \App\Validators\PasswordPolicy::validate($data['password'] ?? '');
        if (!empty($policyErrors)) {
            $errors = array_merge($errors, $policyErrors);
        }

        return $errors;
    }

    public function register(array $data): array
    {
        $userId = $this->userService->register($data);
        if (!$userId) {
            return ['success' => false, 'message' => 'ثبت‌نام با شکست مواجه شد.'];
        }

        // 🚀 UPG-05: پردازش آسنکرون ثبت‌نام (ارسال ایمیل و لاگینگ به صورت پس‌زمینه)
        $this->eventDispatcher->dispatchAsync(
            'auth.register', 
            new UserRegisteredEvent($userId, $data['email'] ?? '', $this->clientIp())
        );
        
        return ['success' => true, 'message' => 'ثبت‌نام با موفقیت انجام شد.'];
    }

    public function requestPasswordReset(string $email): array
    {
        // 🛡️ Security Hardening: Stop automated exhaustion attacks via precise Action-Rate-Limiting
        $ip = $this->clientIp();
        $rateLimitKey = "pw_reset:" . hash('sha256', "{$email}:{$ip}");
        
        // Threshold limit: 3 recovery attempts per hour
        if (!$this->rateLimiter->attempt($rateLimitKey, 3, 60)) {
            $seconds = $this->rateLimiter->availableIn($rateLimitKey);
            $minutes = (int)ceil($seconds / 60);
            
            $this->logWarning('auth.password_reset.rate_limited', [
                'email' => $email,
                'ip' => $ip
            ]);
            
            return [
                'success' => false, 
                'message' => "تعداد درخواست‌های بازیابی بیش از حد مجاز است. لطفاً {$minutes} دقیقه دیگر امتحان کنید."
            ];
        }

        $user = $this->userModel->findByEmail($email);
        $genericMsg = 'اگر این ایمیل در سیستم ثبت شده باشد، لینک بازیابی برای شما ارسال می‌شود.';

        if (!$user) return ['success' => true, 'message' => $genericMsg];

        $token = bin2hex(random_bytes(32));
        $this->securityModel->createPasswordResetToken($email, $token);
        
        if ($this->emailService) {
            $this->emailService->sendPasswordResetEmail((int)$user->id, $token);
        }

        $this->logger->activity('auth.password_reset.requested', 'درخواست بازیابی رمز عبور', (int)$user->id);
        return ['success' => true, 'message' => $genericMsg];
    }

    public function resetPassword(string $token, string $newPassword): array
    {
        $record = $this->securityModel->findPasswordResetByToken($token);
        $timeout = (int)config('auth.password_reset_ttl', 3600);
        
        if (!$record || (time() - strtotime((string)$record->created_at)) > $timeout) {
            return ['success' => false, 'message' => 'لینک بازیابی نامعتبر یا منقضی شده است.'];
        }

        $user = $this->userModel->findByEmail($record->email);
        if (!$user) return ['success' => false, 'message' => 'کاربر یافت نشد.'];

        $this->userService->changePassword((int)$user->id, $newPassword);
        $this->securityModel->deletePasswordResetByEmail($record->email);

        $this->logger->activity('auth.password_reset.completed', 'بازیابی رمز عبور انجام شد', (int)$user->id);
        return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.'];
    }

    public function check(): bool
    {
        return $this->session->get('logged_in') === true;
    }

    public function user(): ?object
    {
        if (!$this->check()) return null;
        return $this->userModel->find((int)$this->session->get('user_id'));
    }
}