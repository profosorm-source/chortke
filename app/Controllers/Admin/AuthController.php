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
 */
class AuthController extends BaseController
{
    private AuthService $authService;
    private RateLimiter $rateLimiter;

    public function __construct(AuditTrail $auditTrail, AuthService $authService, RateLimiter $rateLimiter)
    {
        parent::__construct();
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

            if (!empty($result['requires_2fa'])) {
                // H22 Fix: مدیریت صحیح لاگین ادمین با احراز هویت دو مرحله ای
                $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)$user->id);
                return redirect('/admin/verify-2fa');
            }

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
        if (!$userId) {
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
        if (!$userId) {
            return $this->json(false, 'نشست نامعتبر است.', [], 401);
        }

        $code = trim((string)$this->request->post('code'));
        if (empty($code)) {
            return $this->json(false, 'لطفاً کد ۶ رقمی را وارد کنید.');
        }

        // CRITICAL-01 Fix: Rate limiting for Admin 2FA verification
        $throttleKey = 'admin_2fa_verify:' . (int)$userId . ':' . get_client_ip();
        $throttle = $this->rateLimiter->attempt($throttleKey, 5, 10); // 5 تلاش در 10 دقیقه
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
            $this->rateLimiter->clear($throttleKey);
            $this->session->remove(SessionKeys::PENDING_2FA_USER_ID);
            
            // CRITICAL-C1 Fix: Redundant regenerate() removed. AuthService::verify2FA -> createSession already handles this.
            $this->session->set('admin_verify_time', time());
            
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
        // MED-02 Fix: Enforce POST + CSRF for admin logout
        if (!$this->request->isPost()) {
            return redirect('/admin/dashboard');
        }
        
        app(\Core\CSRF::class)->validate();

        try {
            $userId = user_id();

            // خروج از پنل
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

$this->authService->logout();

return redirect('/admin/login');

        // catch خروج
} catch (\Exception $e) {
    $this->logger->error('admin.logout.failed', [
        'channel' => 'admin_auth',
        'user_id' => $userId ?? null,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
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
