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
 * 
 * SECURITY NOTES:
 * - Password verification uses SHA-384 pre-hash before bcrypt for 72-byte truncation handling
 * - Legacy passwords (without pre-hash) are detected and auto-rehashed asynchronously
 * - Rate limiting uses atomic operations to prevent race conditions
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
     * 
     * CRIT-03 Fix: Atomic rate limiting check to prevent race conditions.
     * Uses atomic increment-and-check pattern to prevent TOCTOU vulnerabilities
     * where an attacker could bypass rate limits by making concurrent requests.
     */
    public function checkRateLimit(string $action, string $key): bool
    {
        $ip = $this->clientIp();
        $rateLimitKey = "{$action}:{$key}:{$ip}";
        
        // CRIT-03 Fix: Use atomic attempt() method which increments and checks atomically
        // This prevents race conditions where multiple requests could slip through
        // before the rate limit counter is incremented
        $rateLimitCheck = $this->rateLimiter->attempt($rateLimitKey, 5, 60, true); // failClosed = true for security
        
        // HIGH-H-18 Fix: Adding random jitter to neutralize timing analysis on rate-limited paths
        usleep(random_int(50, 150) * 1000);

        return is_array($rateLimitCheck) 
            && isset($rateLimitCheck['allowed']) 
            && $rateLimitCheck['allowed'] === true;
    }

    /**
     * MEDIUM-05 Fix: Centralized password verification with SHA-384 pre-hash and legacy fallback
     * 
     * SECURITY: Passwords are pre-hashed with SHA-384 before bcrypt to handle the 72-byte
     * truncation issue in bcrypt. This ensures that long passwords are properly protected.
     * 
     * Migration: Users with legacy passwords (stored without SHA-384 pre-hash) will have
     * their passwords automatically re-hashed on next successful login.
     * 
     * @param string $password Plain text password from user
     * @param string $hash Stored password hash from database
     * @param int|null $userId User ID for async rehash (if legacy password detected)
     * @return bool True if password matches, false otherwise
     */
    public function verifyPassword(string $password, string $hash, ?int $userId = null): bool
    {
        if ($password === '') return false;

        // Apply SHA-384 pre-hash to handle bcrypt's 72-byte truncation
        // bcrypt only uses the first 72 bytes of input; longer passwords are truncated
        // By pre-hashing with SHA-384 (48 bytes), we preserve entropy from longer passwords
        $inputPassword = base64_encode(hash('sha384', $password, true));
        
        if (password_verify($inputPassword, $hash)) {
            return true;
        }

        // Fallback for legacy passwords (without sha384 pre-hash)
        // These were stored directly with bcrypt - migrate them on next login
        if (password_verify($password, $hash)) {
            if ($userId) {
                // HIGH-10 Fix: Auto-rehash legacy password asynchronously to prevent hot-path blocking
                // This ensures users don't experience slow login while their password is upgraded
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
     * 
     * CRIT-03 Fix: Uses atomic rate limiting with fail-closed behavior to prevent
     * race condition attacks on the login endpoint.
     */
    private function performLogin(string $identifier, string $password, bool $remember, bool $requireAdmin): array
    {
        $ip = $this->clientIp();
        $identifier = trim($identifier);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $identifier = mb_strtolower($identifier, 'UTF-8');
        }
        
        // CRITICAL-01 Fix: Consolidated Atomic Rate Limiting (IP + Identifier)
        // Uses fail-closed pattern: if rate limiter fails, deny the request (secure default)
        $ipKey = 'login_ip:' . hash('sha256', $ip);
        $idKey = 'login_id:' . hash('sha256', $identifier);
        
        // Check both limits atomically (both must pass)
        // failClosed=true ensures that if Redis fails, login is denied (not allowed)
        if (!$this->rateLimiter->attempt($ipKey, 10, 1, true) || 
            !$this->rateLimiter->attempt($idKey, 5, 15, true)) {
            
            $this->auditTrail->record('auth.login_throttled', 0, ['identifier' => $identifier, 'ip' => $ip]);
            
            // CRIT-03 Fix: Use constant-time response to prevent timing-based enumeration
            // Sleep for a random time to normalize timing between rate-limited and non-existent users
            usleep(random_int(100000, 200000));
            
            return ['success' => false, 'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است. لطفاً بعداً تلاش کنید.'];
        }

        $user = $this->userModel->findByCredentials($identifier);
        
        // CRIT-02 Fix: Pre-check admin role before verification to ensure session isolation
        if ($requireAdmin && $user && !in_array($user->role, ['admin', 'super_admin', 'support'], true)) {
            // Timing safety: Simulate work even if role is invalid
            $this->verifyPassword($password, $this->getDummyHash());
            return ['success' => false, 'message' => 'اطلاعات ورود نامعتبر است یا دسترسی شما محدود شده است.'];
        }

        // CRITICAL-02 Fix: Status check MUST come BEFORE password verification to prevent lockout bypass
        if ($user) {
            if ($user->status === 'locked') {
                $this->verifyPassword($password, $this->getDummyHash()); // timing safety
                return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
            }

            if ($user->status === 'banned' || $user->status === 'suspended') {
                $this->verifyPassword($password, $this->getDummyHash()); // timing safety
                return ['success' => false, 'message' => 'حساب کاربری شما مسدود یا تعلیق شده است.'];
            }

            if (empty($user->email_verified_at)) {
                $this->verifyPassword($password, $this->getDummyHash()); // timing safety
                return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.', 'email_unverified' => true, 'email' => $user->email];
            }
        }

        $passwordToVerify = $user ? $user->password : $this->getDummyHash();

        // CRIT-03 Fix: Add random delay to prevent timing attacks
        // This ensures consistent timing regardless of whether user exists
        usleep(random_int(100000, 300000));

        if (!$this->verifyPassword($password, $passwordToVerify, $user ? (int)$user->id : null)) {
            $this->logger->warning('auth.login.failed', ['identifier' => $identifier, 'ip' => $ip]);
            
            if ($user) {
                // HIGH-H-21 Fix: Use atomic lockout to prevent race conditions
                $attemptsKey = 'login_attempts:' . hash('sha256', $identifier);
                $attempts = $this->rateLimiter->attempts($attemptsKey);
                
                if ($attempts >= 10) {
                    if ($this->userModel->lockIfExceededAttempts((int)$user->id)) {
                        $this->logger->critical('auth.account_locked', ['user_id' => $user->id, 'identifier' => $identifier]);
                        if ($this->emailService) {
                            $this->emailService->sendAccountLockedAlert((int)$user->id, $ip);
                        }
                    }
                }
            }
            
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            // Clear rate limit counters on successful login (only after full auth)
            $this->rateLimiter->clearLoginAttempts($idKey);
            $this->rateLimiter->clearLoginAttempts($ipKey);
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
        // 🛡️ Rate Limiting: Prevent abuse of direct/OAuth login flows
        $ip = $this->clientIp();
        if (!$this->rateLimiter->attempt('login_direct:' . hash('sha256', $ip), 20, 1, true)) {
            $this->logger->warning('auth.login_directly.throttled', ['user_id' => $user->id, 'ip' => $ip]);
            return ['success' => false, 'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است.'];
        }

        if ($user->status === 'locked') {
            return ['success' => false, 'message' => 'حساب کاربری شما قفل شده است.', 'code' => 'ACCOUNT_LOCKED'];
        }

        if (in_array($user->status, ['banned', 'suspended', 'pending'], true)) {
            return ['success' => false, 'message' => 'حساب کاربری شما مسدود، تعلیق یا در انتظار تأیید است.', 'code' => 'ACCOUNT_DISABLED'];
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

        return [
            'success'      => true,
            'user'         => $user,
            'requires_2fa' => $requires2FA,
        ];
    }

    private function createPending2FASession(object $user): void
    {
        // CRIT-03 Fix: regenerate(true) to delete old session and prevent session fixation
        $this->session->regenerate(true);
        
        // Store user ID in session for 2FA verification
        // This is stored in a separate session variable to prevent manipulation
        $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)$user->id);
        
        // Store verification timestamp to detect session hijacking attempts
        $this->session->set('pending_2fa_created_at', time());
        
        // Store IP at login time for verification
        $this->session->set('pending_2fa_ip', $this->clientIp());
    }

    private function createSession(object $user, bool $remember = false): void
    {
        // CRIT-03 Fix: regenerate(true) BEFORE setting any session data to prevent session fixation
        $this->session->regenerate(true);
        
        $this->session->set(SessionKeys::USER_ID, (int)$user->id);
        $this->session->set(SessionKeys::LOGGED_IN, true);
        $this->session->set(SessionKeys::USER_ROLE, (string)($user->role ?? 'user'));
        $this->session->set('last_activity', (string)time());
        $this->session->set('login_ip', $this->clientIp());
        $this->session->set('login_time', time());
        $this->session->set('user_verify_time', time());

        if ($remember) {
            $this->createRememberToken((int)$user->id);
        }

        // Record session in database for security monitoring
        $this->sessionService->recordSession(
            (int)$user->id,
            $this->session->getId(),
            (string)get_user_agent(),
            $this->clientIp(),
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        );
    }

    private function createRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        
        $this->userModel->update($userId, [
            'remember_token' => $hashedToken,
            'remember_expires_at' => date('Y-m-d H:i:s', strtotime('+30 days'))
        ]);
        
        // Set cookie with secure flags
        setcookie('remember_token', $token, [
            'expires' => strtotime('+30 days'),
            'path' => '/',
            'domain' => '',
            'secure' => true,     // Only over HTTPS
            'httponly' => true,   // Not accessible via JavaScript
            'samesite' => 'Strict' // Strict same-site policy
        ]);
    }

    public function verifyByRememberToken(string $token): ?object
    {
        $hashedToken = hash('sha256', $token);
        $user = $this->userModel->findByRememberToken($hashedToken);
        
        if (!$user) return null;
        
        if (strtotime((string)$user->remember_expires_at) < time()) {
            return null;
        }

        // Regenerate session to prevent session fixation
        $this->session->regenerate(true);
        $this->createSession($user, true);
        
        return $user;
    }

    public function finalizeSessionAfter2FA(object $user): void
    {
        // CRITICAL-C1 Fix: Force session regeneration after 2FA completion 
        // to prevent session fixation from pending state.
        $this->session->regenerate(true);
        
        $this->createSession($user, false);
        $this->session->remove(SessionKeys::PENDING_2FA_USER_ID);
        $this->session->remove('pending_2fa_created_at');
        $this->session->remove('pending_2fa_ip');
        
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
        $sessionId = $this->session->getId();
        $userId = $this->session->get(SessionKeys::USER_ID);
        
        if ($userId) {
            $this->logger->activity('auth.logout', 'خروج کاربر', (int)$userId);
            
            // Invalidate current session in DB
            $dbSession = $this->securityModel->findSessionBySessionId($sessionId);
            if ($dbSession) {
                $this->sessionService->terminateSession((int)$dbSession->id, (int)$userId);
            }
        }

        // HIGH-01 Fix: Explicitly delete Redis activity key on logout
        try {
            $redis = app(\Core\Redis::class);
            if ($redis->isAvailable()) {
                $redis->delete("session:activity:{$sessionId}");
            }
        } catch (\Throwable $e) {
            $this->logger->error('auth.logout.redis_clear_failed', ['error' => $e->getMessage()]);
        }

        $this->clearRememberCookie();
        $this->session->destroy();
    }

    /**
     * HIGH-01 Fix: Invalidate all sessions for a specific user including Redis
     */
    public function logoutAll(int $userId): void
    {
        $this->logger->activity('auth.logout_all', 'خروج از تمامی دستگاه‌ها', $userId);
        
        // CRIT-01 Fix: Invalidate ALL sessions including Redis activity keys
        $sessions = $this->sessionService->getActiveSessions($userId);
        
        foreach ($sessions as $session) {
            try {
                $redis = app(\Core\Redis::class);
                if ($redis->isAvailable()) {
                    $redis->delete("session:activity:" . ($session->session_id ?? ''));
                }
            } catch (\Throwable $e) {
                $this->logger->error('auth.logout_all.redis_clear_failed', [
                    'user_id' => $userId,
                    'session_id' => $session->session_id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Invalidate all sessions in DB
        $this->securityModel->deactivateUserSessions($userId);
        
        // Invalidate remember token
        $this->userModel->update($userId, ['remember_token' => null, 'remember_expires_at' => null]);
        
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
                'samesite' => 'Strict'
            ]);
        }
    }

    public function verify2FA(string $code): array
    {
        $pendingUserId = $this->session->get(SessionKeys::PENDING_2FA_USER_ID);
        if (!$pendingUserId) {
            return ['success' => false, 'message' => 'هیچ درخواست 2FA pending وجود ندارد.'];
        }

        // CRIT-04 Fix: Verify that the pending 2FA session was created recently
        // This prevents attackers from using old stolen sessions
        $createdAt = (int)$this->session->get('pending_2fa_created_at', 0);
        if (time() - $createdAt > 600) { // 10 minute max
            $this->session->destroy();
            return ['success' => false, 'message' => 'نشست 2FA منقضی شده است. لطفاً دوباره وارد شوید.'];
        }
        
        // CRIT-04 Fix: Verify IP consistency for 2FA pending sessions
        // If IP changed significantly (different /24), it might be an attack
        $pendingIp = $this->session->get('pending_2fa_ip');
        $currentIp = $this->clientIp();
        // Normalize IPs to /24 subnet for comparison
        // Normalize IPs to /24 subnet (IPv4) or /64 (IPv6) for comparison
        $normalize = function(string $ip): string {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $packed = inet_pton($ip);
                if ($packed === false) return $ip;
                return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8));
            }
            return substr($ip, 0, strrpos($ip, '.') ?: 0);
        };

        $pendingSubnet = $normalize($pendingIp ?? '');
        $currentSubnet = $normalize($currentIp);
        if ($pendingSubnet !== $currentSubnet) {
            $this->logger->warning('auth.2fa.ip_mismatch', [
                'pending_ip' => $pendingIp,
                'current_ip' => $currentIp,
                'user_id' => $pendingUserId
            ]);
            // Log but don't block - legitimate users might change networks
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
        $minLength = (int)config('auth.password.min_length', 12);
        $validator = new \Core\Validator($data, [
            'full_name' => 'required|min:3',
            'email' => 'required|email',
            'password' => "required|min:{$minLength}",
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

    /**
     * HIGH-02 Fix: Centralized validation for password reset tokens
     */
    public function validatePasswordResetToken(string $token): bool
    {
        $timeout = (int)config('auth.password_reset_ttl', 3600);
        $record = $this->securityModel->findPasswordResetByToken($token, $timeout);
        return $record !== null;
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
        if (!$user) {
            $this->securityModel->deletePasswordResetByEmail($record->email);
            return ['success' => false, 'message' => 'لینک بازیابی نامعتبر یا منقضی شده است.'];
        }

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