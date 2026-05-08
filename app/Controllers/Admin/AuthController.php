<?php

namespace App\Controllers\Admin;


use App\Controllers\BaseController;
use App\Services\AuditTrail;
use App\Services\Auth\AuthService;
use App\Services\Shared\PolicyService;
use Core\Logger;


/**
 * AuthController - احراز هویت ادمین
 */
class AuthController extends BaseController
{
    protected \App\Contracts\LoggerInterface $logger;
    private AuditTrail $auditTrail;
    private AuthService $authService;
    protected PolicyService $policyService;
    
    

  public function __construct(AuditTrail $auditTrail, AuthService $authService, Logger $logger, PolicyService $policyService)
{
    parent::__construct();
    
    $this->authService = $authService;
    $this->auditTrail = $auditTrail;
    $this->logger = $logger;
    $this->policyService = $policyService;
    
}


    /**
     * صفحه لاگین
     */
   public function showLogin()
{
    $isLoggedIn = (bool) $this->session->get('logged_in', false);
    $userId = $this->session->get('user_id');
    $role = (string) ($this->session->get('user_role') ?? $this->session->get('role') ?? '');

    if ($isLoggedIn && $userId && in_array($role, ['admin', 'super_admin', 'support'], true)) {
        return redirect('/admin/dashboard');
    }

    return view('admin.login');
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

            $result = $this->authService->login($email, $password, $remember);

            if (!($result['success'] ?? false)) {
                $this->logger->warning('admin.login.failed', [
                    'channel' => 'admin_auth',
                    'email' => $email,
                    'ip' => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                ]);

                $this->session->setFlash('error', (string)($result['message'] ?? 'اطلاعات ورود نامعتبر است.'));
                return view('admin/login');
            }

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

            if (!in_array((string)($user->role ?? ''), ['admin', 'super_admin', 'support'], true)) {
                $this->logger->warning('admin.unauthorized_access', [
                    'channel' => 'admin_auth',
                    'user_id' => (int)($user->id ?? 0),
                    'email' => $email,
                ]);

                $this->authService->logout();
                $this->session->setFlash('error', 'دسترسی غیرمجاز.');
                return view('admin/login');
            }

            // استفاده از PolicyService (Sprint 5) برای authorization
            if (!$this->policyService->isAdmin($user)) {
                $this->logger->warning('admin.not_authorized', [
                    'user_id' => $user->id,
                    'email' => $email,
                ]);
                $this->authService->logout();
                $this->session->setFlash('error', 'شما اجازه دسترسی به پنل ادمین را ندارید');
                return view('admin/login');
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
            return view('admin/login');
        }
    }

    return view('admin/login');
}
    /**
     * خروج
     */
    public function logout()
    {
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
}
