<?php

namespace App\Controllers\Admin;


use App\Controllers\BaseController;
use App\Services\AuditTrail;
use App\Services\Auth\AuthService;
use App\Services\Shared\PolicyService;
use Core\Logger;
use Core\RateLimiter;
use App\Constants\SessionKeys;


/**
 * AuthController - احراز هویت ادمین
 * 
 * SECURITY NOTES:
 * - Complete session isolation from regular user sessions
 * - Separate session storage for admin auth state
 * - IP and timestamp tracking for admin 2FA pending sessions
 * - Stricter rate limiting than regular auth
 */
class AuthController extends BaseController
{
    private AuthService $authService;
    private RateLimiter $rateLimiter;

    public function __construct(AuditTrail $auditTrail, AuthService $authService, RateLimiter $rateLimiter, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->authService = $authService;
        $this->auditTrail = $auditTrail;
        $this->rateLimiter = $rateLimiter;
        // $logger and $policyService are inherited from BaseController
    }


    /**
     * صفحه لاگین
     */
   public function showLogin()
{
    $isLoggedIn = (bool) $this->session->get(SessionKeys::LOGGED_IN, false);
    $userId = $this->session->get(SessionKeys::USER_ID);
    $role = (string) ($this->session->get(SessionKeys::USER_ROLE) ?? '');

    if ($isLoggedIn && $userId && in_array($role, ['admin', 'super_admin', 'support'], true)) {
        return redirect('/admin/dashboard');
    }

    // ✅ استفاده از مسیر یکسان: 'admin/login' (اسلش بجای نقطه)
    return view('admin/login');
}
    /**
     * پردازش لاگین
     */
  public function login()
{
    if ($this->request->isPost()) {
        try {
            $email = trim((string)$this->request->post('email'));
            $password = (string)$this->request->post('password');
            $remember = (bool)$this->request->post('remember');

            if (empty($email) || empty($password)) {
                $this->session->setFlash('error', 'ایمیل و رمز عبور الزامی است.');
                return view('admin/login');
            }

            // HIGH-09 Fix: Normalize IP (IPv6 /64) and use SHA-256 for rate limit keys
            $normalizedIp = $this->normalizeIp(get_client_ip());
            $throttleKey = 'admin:' . hash('sha256', $normalizedIp) . ':' . hash('sha256', $email);
            
            $throttle = $this->rateLimiter->checkLoginAttempt($throttleKey);
            if (!$throttle['allowed']) {
                $this->session->setFlash('error', $throttle['message']);
                return view('admin/login');
            }

            // HIGH-03 Fix: Use a single generic error message to prevent User Enumeration
            $genericError = 'اطلاعات ورود نامعتبر است یا دسترسی شما محدود شده است.';
            
            $result = $this->authService->loginAsAdmin($email, $password, $remember);

            if (!($result['success'] ?? false)) {
                $this->logger->warning('admin.login.failed', [
                    'channel' => 'admin_auth',
                    'email' => $email,
                    'ip' => get_client_ip(),
                    'reason' => 'auth_failed'
                ]);

                $this->session->setFlash('error', $genericError);
                // MED-09 Fix: Use redirect instead of view() to follow PRG pattern
                return redirect('/admin/login');
            }

            // پاک کردن تلاش‌های ناموفق در صورت ورود موفق
            $this->rateLimiter->clearLoginAttempts($throttleKey);

            $user = $result['user'] ?? null;
            if (!is_object($user)) {
                $this->logger->error('admin.login.invalid_user_payload', [
                    'channel' => 'admin_auth',
                    'email' => $email,
                ]);
                $this->authService->logout();
                $this->session->setFlash('error', 'خطای داخلی در پردازش ورود.');
                return view('admin/login');
            }

            // CRIT-02 Fix: Role is already verified inside loginAsAdmin
            // Double check here just for defense-in-depth, but we use redirect to prevent double submit
            if (!in_array((string)($user->role ?? ''), ['admin', 'super_admin', 'support'], true)) {
                $this->authService->logout();
                $this->session->setFlash('error', $genericError);
                return redirect('/admin/login');
            }

            if (!$this->policyService->isAdmin($user)) {
                $this->logger->warning('admin.not_authorized', [
                    'user_id' => $user->id,
                    'email' => $email,
                ]);
                $this->authService->logout();
                $this->session->setFlash('error', $genericError);
                return redirect('/admin/login');
            }

            if (!empty($result['requires_2fa'])) {
                // H22 Fix: مدیریت صحیح لاگین ادمین با احراز هویت دو مرحله ای
                // HIGH-09 Fix: Store admin-specific pending 2FA with isolation
                $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)$user->id);
                $this->session->set('admin_pending_2fa', true); // Admin-specific flag
                $this->session->set('admin_pending_2fa_created', time()); // Timestamp for timeout
                $this->session->set('admin_pending_2fa_ip', get_client_ip()); // IP binding
                
                // CRITICAL-02 Fix: Log pending 2FA state
                $this->logger->info('admin.login.pending_2fa', [
                    'channel' => 'admin_auth',
                    'user_id' => $user->id,
                    'email' => $email,
                    'ip' => get_client_ip()
                ]);
                return redirect('/admin/verify-2fa');
            }

            // CRITICAL-02 Fix: Only log success and record audit trail if 2FA is NOT required
            $this->logger->activity(
                'admin.login',
                'ورود موفق به پنل مدیریت',
                (int)$user->id,
                [
                    'channel' => 'admin_auth',
                    'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                    'user_agent' => function_exists('get_user_agent') ? get_user_agent() : '',
                    'remember' => $remember,
                ]
            );

            // MEDIUM-M-08 Fix: Record audit trail ONLY after full authentication (2FA not required here)
            $this->auditTrail->record(
                'admin.login',
                (int)$user->id,
                [
                    'channel' => 'admin_auth',
                    'type' => 'admin',
                    'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
                (int)$user->id
            );

            return redirect('/admin/dashboard');
        } catch (\Throwable $e) {
            $this->logger->error('admin.login.exception', [
                'channel' => 'admin_auth',
                'email' => $email ?? null,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->session->setFlash('error', 'خطای سرور، لطفا دوباره تلاش کنید.');
            // MED-10 Fix: Use redirect instead of view() to follow PRG pattern and prevent double submit
            return redirect('/admin/login');
        }
    }

    return view('admin/login');
}
    /**
     * نمایش صفحه تایید دو مرحله ای ادمین
     */
    public function showVerify2FA()
    {
        $userId = $this->session->get(SessionKeys::PENDING_2FA_USER_ID);
        
        // HIGH-09 Fix: Verify this is admin pending 2FA (not user pending 2FA)
        if (!$userId || !$this->session->get('admin_pending_2fa')) {
            return redirect('/admin/login');
        }
        
        // HIGH-09 Fix: Verify admin pending 2FA hasn't expired
        $createdAt = (int)$this->session->get('admin_pending_2fa_created', 0);
        if (time() - $createdAt > 300) { // 5 minute timeout for admin
            $this->session->destroy();
            $this->session->setFlash('error', 'نشست تأیید ادمین منقضی شده است. لطفاً دوباره لاگین کنید.');
            return redirect('/admin/login');
        }

        // HIGH FIX: Verify IP binding for admin 2FA session
        $expectedIp = $this->session->get('admin_pending_2fa_ip');
        $currentIp = get_client_ip();
        if ($expectedIp && $expectedIp !== $currentIp) {
            $this->logger->critical('admin.2fa.ip_mismatch', [
                'user_id' => $userId,
                'expected_ip' => $expectedIp,
                'current_ip' => $currentIp
            ]);

            $this->session->destroy();
            $this->session->setFlash('error', 'به دلایل امنیتی، نشست شما بسته شد. لطفاً دوباره لاگین کنید.');
            return redirect('/admin/login');
        }

        return view('admin/verify-2fa', ['title' => 'تایید هویت دو مرحله ای']);
    }

    /**
     * پردازش تایید دو مرحله ای ادمین
     */
    public function verify2FA()
    {
        $userId = $this->session->get(SessionKeys::PENDING_2FA_USER_ID);
        
        // HIGH-09 Fix: Verify admin pending 2FA exists and is valid
        if (!$userId || !$this->session->get('admin_pending_2fa')) {
            return $this->json(false, 'نشست نامعتبر است.', [], 401);
        }
        
        // HIGH-09 Fix: Verify admin pending 2FA hasn't expired
        $createdAt = (int)$this->session->get('admin_pending_2fa_created', 0);
        if (time() - $createdAt > 600) {
            $this->session->destroy();
            return $this->json(false, 'نشست تأیید ادمین منقضی شده است.', [], 401);
        }
        
        // HIGH-09 Fix: Verify IP consistency for admin 2FA
        $storedIp = $this->session->get('admin_pending_2fa_ip');
        $currentIp = get_client_ip();
        if ($storedIp && $storedIp !== $currentIp) {
            $this->logger->critical('admin.2fa.ip_mismatch', [
                'user_id' => $userId,
                'stored_ip' => $storedIp,
                'current_ip' => $currentIp
            ]);

            $this->session->destroy();
            return $this->json(false, 'نشست نامعتبر است.', [], 401);
        }

        $code = trim((string)$this->request->post('code'));
        if (empty($code)) {
            return $this->json(false, 'لطفاً کد ۶ رقمی را وارد کنید.');
        }

        // Validate code format
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            return $this->json(false, 'لطفاً کد ۶ رقمی معتبر وارد کنید.');
        }

        // CRITICAL-01 Fix: Rate limiting for Admin 2FA verification (stricter than user 2FA)
        $throttleKey = 'admin_2fa_verify:' . (int)$userId . ':' . get_client_ip();
        $throttle = $this->rateLimiter->attempt($throttleKey, 3, 10, true); // 3 attempts in 10 minutes (stricter for admin)
        if (!$throttle) {
            $this->logger->warning('admin.2fa.bruteforce_attempt', [
                'user_id' => $userId,
                'ip' => get_client_ip()
            ]);
            $this->session->destroy(); // Destroy session on brute-force detection
            return $this->json(false, 'تعداد تلاش‌ها بیش از حد مجاز است. لطفا دوباره لاگین کنید.', [], 429);
        }

        $result = $this->authService->verify2FA($code);

        if ($result['success']) {
            // MEDIUM-02 Fix: Explicitly regenerate session on successful 2FA verification to prevent session fixation
            $this->session->regenerate(true);

            $this->rateLimiter->clear($throttleKey);
            $this->session->remove(SessionKeys::PENDING_2FA_USER_ID);
            $this->session->remove('admin_pending_2fa');
            $this->session->remove('admin_pending_2fa_created');
            $this->session->remove('admin_pending_2fa_ip');
            
            $this->session->set('admin_verify_time', time());
            $this->session->set('admin_session', true); // Mark session as admin
            
            $this->logger->activity(
                'admin.2fa.verified',
                'تایید موفق 2FA پنل مدیریت',
                (int)$userId,
                ['channel' => 'admin_auth']
            );

            // CRITICAL-04 Fix: Record specific 'admin.login.2fa_completed' event after 2FA
            // HIGH-02 Fix: Record audit trail for admin login with 2FA
            $this->auditTrail->record(
                'admin.login',
                (int)$userId,
                [
                    'channel' => 'admin_auth',
                    'type' => 'admin_with_2fa',
                    'ip' => get_client_ip(),
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
                (int)$userId
            );

            $this->auditTrail->record(
                'admin.login.2fa_completed',
                (int)$userId,
                [
                    'channel' => 'admin_auth',
                    'type' => 'admin',
                    '2fa' => true,
                    'ip' => get_client_ip(),
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
                (int)$userId
            );

            return $this->json(true, 'ورود موفقیت‌آمیز بود.', ['redirect' => url('/admin/dashboard')]);
        }

        return $this->json(false, $result['message'] ?? 'کد وارد شده نامعتبر است.');
    }

    /**
     * خروج
     */
    public function logout()
    {
        if (!$this->request->isPost()) {
            return redirect('/admin/dashboard');
        }
        $userId = null;
        try {
            $this->validateCsrf();
            $userId = user_id();

            if ($userId) {
                $this->logger->activity(
                    'admin.logout',
                    'خروج از پنل مدیریت',
                    $userId,
                    ['channel' => 'admin_auth']
                );

                $this->auditTrail->record(
                    'admin.logout',
                    $userId,
                    [
                        'channel' => 'admin_auth',
                        'type' => 'admin',
                    ],
                    $userId
                );
            }

            // Clear admin-specific session flags
            $this->session->remove('admin_pending_2fa');
            $this->session->remove('admin_pending_2fa_created');
            $this->session->remove('admin_pending_2fa_ip');
            $this->session->remove('admin_verify_time');
            $this->session->remove('admin_session');

            $this->authService->logout();

            return redirect('/admin/login');

        } catch (\Throwable $e) {
            $this->logger->error('admin.logout.failed', [
                'channel' => 'admin_auth',
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e)
            ]);
            return redirect('/admin/login');
        }
    }

    /**
     * MEDIUM-02 Fix: Normalize IPv6 to /64 prefix for consistent security checks
     */
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