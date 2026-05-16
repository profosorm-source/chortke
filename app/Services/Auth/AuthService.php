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
use App\Constants\SessionKeys;

/**
 * AuthService
 *
 * هماهنگ‌کننده اصلی احراز هویت.
 */
class AuthService extends \App\Services\BaseService
{
    private static ?string $cachedDummyHash = null;

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
    /**
     * CRIT-03 Fix: تغییر منطق به بازگشت مقدار Boolean برای سازگاری با کنترلرها
     */
    public function checkRateLimit(string $action, string $key): bool
    {
        $ip = $this->clientIp();
        $rateLimitKey = "{$action}:{$key}:{$ip}";
        $rateLimitCheck = $this->rateLimiter->checkLoginAttempt($rateLimitKey);
        
        // HIGH-H-18 Fix: Adding random jitter to neutralize timing analysis on rate-limited paths
        usleep(random_int(50, 150) * 1000);

        return is_array($rateLimitCheck) 
            && isset($rateLimitCheck['allowed']) 
            && $rateLimitCheck['allowed'] === true;
    }

    /**
     * MEDIUM-05 Fix: Centralized password verification with SHA-384 pre-hash and legacy fallback
     */
    public function verifyPassword(string $password, string $hash, ?int $userId = null): bool
    {
        if ($password === '') return false;

        $inputPassword = base64_encode(hash('sha384', $password, true));
        
        if (password_verify($inputPassword, $hash)) {
            return true;
        }

        // Fallback for legacy passwords (without sha384 pre-hash)
        if (password_verify($password, $hash)) {
            if ($userId) {
                // HIGH-10 Fix: Auto-rehash legacy password asynchronously to prevent hot-path blocking
                $this->eventDispatcher->dispatchAsync(
                    'auth.rehash_password',
                    ['user_id' => $userId, 'password' => $password]
                );
            }
            return true;
        }

        return false;
    }

    public function login(string $identifier, string $password, bool $remember = false): array
    {
        return $this->performLogin($identifier, $password, $remember, false);
    }

    /**
     * CRIT-02 Fix: متد اختصاصی برای ورود ادمین با چک کردن نقش قبل از ساخت سشن
     */
    public function loginAsAdmin(string $email, string $password, bool $remember = false): array
    {
        return $this->performLogin($email, $password, $remember, true);
    }

    /**
     * منطق مشترک ورود با قابلیت فیلتر بر اساس ادمین بودن
     */
    private function performLogin(string $identifier, string $password, bool $remember, bool $requireAdmin): array
    {
        $ip = $this->clientIp();
        $identifier = trim($identifier);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $identifier = mb_strtolower($identifier, 'UTF-8');
        }
        
        // CRITICAL-01 Fix: Consolidated Rate Limiting (IP + Identifier)
        if (!$this->rateLimiter->attempt('login_ip:' . hash('sha256', $ip), 10, 1, true) || 
            !$this->rateLimiter->attempt('login_id:' . hash('sha256', $identifier), 5, 15, true)) {
            
            $this->auditTrail->record('auth.login_throttled', 0, ['identifier' => $identifier, 'ip' => $ip]);
            return ['success' => false, 'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است. لطفاً بعداً تلاش کنید.'];
        }

        $user = $this->userModel->findByCredentials($identifier);
        
        // CRIT-02 Fix: Pre-check admin role before verification to ensure session isolation
        if ($requireAdmin && $user && !in_array($user->role, ['admin', 'super_admin', 'support'], true)) {
            // Timing safety: Simulate work even if role is invalid
            $this->verifyPassword($password, $this->getDummyHash());
            return ['success' => false, 'message' => 'اطلاعات ورود نامعتبر است یا دسترسی شما محدود شده است.'];
        }

        $passwordToVerify = $user ? $user->password : $this->getDummyHash();

        usleep(random_int(100000, 300000));

        if (!$this->verifyPassword($password, $passwordToVerify, $user ? (int)$user->id : null)) {
            $this->logger->warning('auth.login.failed', ['identifier' => $identifier, 'ip' => $ip]);
            
            if ($user) {
                $attempts = $this->rateLimiter->getAttempts('login_id:' . hash('sha256', $identifier));
                if ($attempts >= 10 && $user->status !== 'locked') {
                    $this->userModel->update((int)$user->id, ['status' => 'locked']);
                    $this->logger->critical('auth.account_locked', ['user_id' => $user->id, 'identifier' => $identifier]);
                    if ($this->emailService) {
                        $this->emailService->sendAccountLockedAlert((int)$user->id, $ip);
                    }
                }
            }
            
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ($user->status === 'locked') {
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ($user->status === 'banned' || $user->status === 'suspended') {
            return ['success' => false, 'message' => 'حساب کاربری شما مسدود یا تعلیق شده است.'];
        }

        if (empty($user->email_verified_at)) {
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.', 'email_unverified' => true, 'email' => $user->email];
        }

        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            $this->rateLimiter->clearLoginAttempts('login_id:' . hash('sha256', $identifier));
            $this->rateLimiter->clearLoginAttempts('login_ip:' . hash('sha256', $ip));
            $this->createSession($user, $remember);
        } else {
            $this->createPending2FASession($user);
        }

        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, $this->clientIp(), get_user_agent())
        );
        
        return [
            'success'      => true,
            'message'      => 'ورود موفقیت‌آمیز بود.',
            'user'         => $user,
            'requires_2fa' => $requires2FA,
        ];
    }

    /**
     * ورود مستقیم کاربر (برای سیستم‌های احراز هویت مکمل مانند OAuth)
     * H19 Fix: تجمیع فرآیند ساخت Session و توزیع 2FA برای تضمین یکپارچگی ورودهای شخص‌ثالث
     */
    public function loginDirectly(object $user): array
    {
        if ($user->status === 'locked') {
            return ['success' => false, 'message' => 'حساب کاربری شما قفل شده است.', 'code' => 'ACCOUNT_LOCKED'];
        }

        if (in_array($user->status, ['banned', 'suspended'], true)) {
            return ['success' => false, 'message' => 'حساب کاربری شما مسدود یا تعلیق شده است.', 'code' => 'ACCOUNT_DISABLED'];
        }

        // HIGH-H-14 Fix: Enforce email verification check for direct/OAuth logins
        if (empty($user->email_verified_at)) {
            return ['success' => false, 'message' => 'ایمیل کاربر تأیید نشده است.'];
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

        // 🛡️ Rate Limiting: Prevent abuse of direct/OAuth login flows
        $ip = $this->clientIp();
        if (!$this->rateLimiter->attempt('login_direct:' . hash('sha256', $ip), 20, 1, true)) {
            $this->logger->warning('auth.login_directly.throttled', ['user_id' => $user->id, 'ip' => $ip]);
            return ['success' => false, 'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است.'];
        }

        return [
            'success'      => true,
            'user'         => $user,
            'requires_2fa' => $requires2FA,
        ];
    }

    private function createPending2FASession(object $user): void
    {
        // CRIT-03 Fix: regenerate(true) to delete old session
        $this->session->regenerate(true);
        $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)$user->id);
    }

    private function createSession(object $user, bool $remember = false): void
    {
        // CRIT-03 Fix: regenerate(true) BEFORE setting data
        $this->session->regenerate(true);
        $this->session->set(SessionKeys::USER_ID,  (int)$user->id);
        $this->session->set(SessionKeys::USERNAME, $user->username ?? '');
        $this->session->set(SessionKeys::USER_EMAIL, $user->email);
        $this->session->set(SessionKeys::USER_ROLE, $user->role);
        $this->session->set(SessionKeys::IS_ADMIN, in_array($user->role, ['admin', 'super_admin'], true));
        $this->session->set(SessionKeys::LOGGED_IN, true);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            // MED-07 Fix: Using hash_hmac for remember_token to protect against rainbow tables
            $hashedToken = hash_hmac('sha256', $token, (string)config('app.key'));
            
            // HIGH-02 Fix: Store hashed token and rotate on use
            $this->userModel->update((int)$user->id, ['remember_token' => $hashedToken]);
            
            $rememberDays = (int)$this->settingService->get('auth_remember_days', 30);
            // H12 Fix: جلوگیری از ست شدن نامعتبر دامین در localhost و محافظت در برابر پارس نادرست
            $host = parse_url(config('app.url', ''), PHP_URL_HOST);
            $cookieDomain = $host && $host !== 'localhost' ? $host : '';

            // 🛡️ Modernized Security Attributes: Strictly enforcing HttpOnly, Secure and Lax SameSite policies
            setcookie('remember_token', $token, [
                'expires' => time() + ($rememberDays * 86400),
                'path' => '/',
                'domain' => $cookieDomain,
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

    public function finalizeSessionAfter2FA(object $user): void
    {
        // CRITICAL-C1 Fix: Force session regeneration after 2FA completion 
        // to prevent session fixation from pending state.
        $this->session->regenerate(true);
        
        $this->createSession($user, false);
        $this->session->remove(SessionKeys::PENDING_2FA_USER_ID);
        
        // CRIT-01 Fix: Ensure consistent identifier normalization for rate-limit clearing
        $identifier = mb_strtolower($user->email ?? (string)$user->username, 'UTF-8');
        
        $this->rateLimiter->clearLoginAttempts('login_id:' . hash('sha256', $identifier));
        $this->rateLimiter->clearLoginAttempts('login_ip:' . hash('sha256', $this->clientIp()));
        
        // Record final login event after 2FA
        $this->auditTrail->record('auth.login.2fa_completed', (int)$user->id, [
            'ip' => $this->clientIp(),
            'user_agent' => get_user_agent()
        ]);
    }

    public function logout(): void
    {
        $userId = $this->session->get(SessionKeys::USER_ID);
        if ($userId) {
            $this->logger->activity('auth.logout', 'خروج کاربر', (int)$userId);
            
            // Invalidate current session in DB
            $dbSession = $this->securityModel->findSessionBySessionId($this->session->getId());
            if ($dbSession) {
                $this->sessionService->terminateSession((int)$dbSession->id, (int)$userId);
            }
        }

        $this->clearRememberCookie();
        $this->session->destroy();
    }

    /**
     * HIGH-01 Fix: Invalidate all sessions for a specific user
     */
    public function logoutAll(int $userId): void
    {
        $this->logger->activity('auth.logout_all', 'خروج از تمامی دستگاه‌ها', $userId);
        
        // Invalidate all sessions in DB
        $this->securityModel->deactivateUserSessions($userId);
        
        // Invalidate remember token
        $this->userModel->update($userId, ['remember_token' => null]);
        
        $this->clearRememberCookie();
        $this->session->destroy();
    }

    private function clearRememberCookie(): void
    {
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
    }

    public function verify2FA(string $code): array
    {
        $pendingUserId = $this->session->get(SessionKeys::PENDING_2FA_USER_ID);
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

        $this->finalizeSessionAfter2FA($user);

        // 🚀 UPG-05: پردازش آسنکرون رویداد پس از تایید موفق دو عاملی
        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, $this->clientIp(), get_user_agent())
        );

        return [
            'success' => true,
            'message' => 'احراز هویت دو مرحله‌ای تأیید شد.',
            'user' => $user,
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
        $policyErrors = \App\Validators\PasswordPolicy::validate($data['password'] ?? '', [
            'username' => $data['username'] ?? '',
            'email' => $data['email'] ?? '',
            'full_name' => $data['full_name'] ?? '',
        ]);
        if (!empty($policyErrors)) {
            $errors = array_merge($errors, $policyErrors);
        }

        return $errors;
    }

    public function register(array $data): array
    {
        $result = $this->userService->register($data);
        if (!$result) {
            return ['success' => false, 'message' => 'ثبت‌نام با شکست مواجه شد.'];
        }

        $userId = $result['id'];
        $plainToken = $result['plain_token'];

        // 🚀 UPG-05: پردازش آسنکرون ثبت‌نام (ارسال ایمیل و لاگینگ به صورت پس‌زمینه)
        $this->eventDispatcher->dispatchAsync(
            'auth.register', 
            new UserRegisteredEvent($userId, $data['email'] ?? '', $this->clientIp(), $plainToken)
        );
        
        return ['success' => true, 'message' => 'ثبت‌نام با موفقیت انجام شد.'];
    }

    public function requestPasswordReset(string $email): array
    {
        // 🛡️ Security Hardening: Stop automated exhaustion attacks via precise Action-Rate-Limiting
        $ip = $this->clientIp();
        $rateLimitKey = "pw_reset:" . hash('sha256', "{$email}:{$ip}");
        
        // Threshold limit: 3 recovery attempts per hour
        // CRIT-07 Fix: failClosed = true for security-sensitive routes
        if (!$this->rateLimiter->attempt($rateLimitKey, 3, 60, true)) {
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

        // MED-01 Fix: Using a consistent execution path to prevent timing-based enumeration
        // Always generate a token to keep DB workload consistent
        $token = bin2hex(random_bytes(32));
        $this->securityModel->createPasswordResetToken($email, $token);

        if ($user) {
            if ($this->emailService) {
                $this->emailService->sendPasswordResetEmail((int)$user->id, $token);
            }
            $this->logger->activity('auth.password_reset.requested', 'درخواست بازیابی رمز عبور', (int)$user->id);
        } else {
            // MED-01 Fix: Artificial delay for non-existent users to match the "email sending" time
            usleep(random_int(100000, 300000));
        }

        return ['success' => true, 'message' => $genericMsg];
    }

    public function resetPassword(string $token, string $newPassword): array
    {
        // HIGH-H-11 Fix: The TTL check is now enforced inside findPasswordResetByToken (DB-level)
        $timeout = (int)config('auth.password_reset_ttl', 3600);
        $record = $this->securityModel->findPasswordResetByToken($token, $timeout);
        
        if (!$record) {
            return ['success' => false, 'message' => 'لینک بازیابی نامعتبر یا منقضی شده است.'];
        }

        $user = $this->userModel->findByEmail($record->email);
        if (!$user) return ['success' => false, 'message' => 'کاربر یافت نشد.'];

        // MEDIUM-05 Fix: Pre-hash password before bcrypt to handle 72-byte truncation
        $passwordToHash = base64_encode(hash('sha384', $newPassword, true));
        $this->userService->changePassword((int)$user->id, $passwordToHash);
        $this->securityModel->deletePasswordResetByEmail($record->email);

        $this->logger->activity('auth.password_reset.completed', 'بازیابی رمز عبور انجام شد', (int)$user->id);
        return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.'];
    }

    private function getDummyHash(): string
    {
        if (self::$cachedDummyHash === null) {
            self::$cachedDummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        }
        return self::$cachedDummyHash;
    }

    public function check(): bool
    {
        return $this->session->get(SessionKeys::LOGGED_IN) === true;
    }

    public function user(): ?object
    {
        if (!$this->check()) return null;
        return $this->userModel->find((int)$this->session->get(SessionKeys::USER_ID));
    }
}