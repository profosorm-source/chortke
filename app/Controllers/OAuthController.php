<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth\OAuthService;
use Core\Request;
use Core\Response;
use App\Constants\SessionKeys;

/**
 * OAuthController — Social Login (Google + Facebook)
 */
class OAuthController extends BaseController
{
    private OAuthService $oauthService;

    public function __construct(OAuthService $oauthService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->oauthService = $oauthService;
    }

    /**
     * هدایت کاربر به صفحه لاگین گوگل
     */
    public function loginGoogle(): void
    {
        $url = $this->oauthService->getGoogleAuthUrl();
        $this->response->redirect($url);
    }

    /**
     * هدایت کاربر به صفحه لاگین فیسبوک
     */
    public function loginFacebook(): void
    {
        $url = $this->oauthService->getFacebookAuthUrl();
        $this->response->redirect($url);
    }

    /**
     * هندلر بازگشت از گوگل
     */
    public function callbackGoogle(): void
    {
        $code = (string)$this->request->get('code');
        $state = (string)$this->request->get('state');

        if (empty($code) || empty($state)) {
            $this->jsonError('پارامترهای بازگشتی نامعتبر است', [], 400);
            return;
        }

        $result = $this->oauthService->handleGoogleCallback($code, $state);

        if ($result['success']) {
            $this->session->regenerate(true);
            app(\Core\CSRF::class)->regenerate();
            // 🛡️ Security Hardening: Handling 2FA checkpoints for social logins
            if (!empty($result['requires_2fa'])) {
                $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)($result['user_id'] ?? $result['user']->id));
                if ($this->request->isAjax()) {
                    $this->jsonSuccess('', ['redirect' => url('verify-2fa')]);
                    return;
                }
                $this->response->redirect(url('verify-2fa'));
                return;
            }

            $message = ($result['is_new'] ?? false) 
                ? 'خوش آمدید! حساب کاربری جدید شما ساخته شد.'
                : 'خوش آمدید!';

            if ($this->request->isAjax()) {
                $this->jsonSuccess($message, ['redirect' => url('dashboard')]);
                return;
            }
            $this->session->setFlash('success', $message);
            $this->response->redirect(url('dashboard'));
            return;
        }

        if (!empty($result['requires_password_confirmation'])) {
            if ($this->request->isAjax()) {
                $this->jsonSuccess($result['message'] ?? '', ['redirect' => url('auth/oauth-confirm')]);
                return;
            }
            $this->session->setFlash('warning', $result['message'] ?? '');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        if ($this->request->isAjax()) {
            $this->jsonError($result['message'] ?? 'خطا در لاگین با گوگل');
            return;
        }
        $this->session->setFlash('error', $result['message'] ?? 'خطا در لاگین با گوگل');
        $this->response->redirect(url('login'));
        return;
    }

    /**
     * هندلر بازگشت از فیسبوک
     */
    public function callbackFacebook(): void
    {
        $code = (string)$this->request->get('code');
        $state = (string)$this->request->get('state');

        if (empty($code) || empty($state)) {
            $this->jsonError('پارامترهای بازگشتی نامعتبر است', [], 400);
            return;
        }

        $result = $this->oauthService->handleFacebookCallback($code, $state);

        if ($result['success']) {
            $this->session->regenerate(true);
            app(\Core\CSRF::class)->regenerate();
            // 🛡️ Security Hardening: Handling 2FA checkpoints for social logins
            if (!empty($result['requires_2fa'])) {
                $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)($result['user_id'] ?? $result['user']->id));
                if ($this->request->isAjax()) {
                    $this->jsonSuccess('', ['redirect' => url('verify-2fa')]);
                    return;
                }
                $this->response->redirect(url('verify-2fa'));
                return;
            }

            $message = ($result['is_new'] ?? false) 
                ? 'خوش آمدید! حساب کاربری جدید شما ساخته شد.'
                : 'خوش آمدید!';

            if ($this->request->isAjax()) {
                $this->jsonSuccess($message, ['redirect' => url('dashboard')]);
                return;
            }
            $this->session->setFlash('success', $message);
            $this->response->redirect(url('dashboard'));
            return;
        }

        if (!empty($result['requires_password_confirmation'])) {
            if ($this->request->isAjax()) {
                $this->jsonSuccess($result['message'] ?? '', ['redirect' => url('auth/oauth-confirm')]);
                return;
            }
            $this->session->setFlash('warning', $result['message'] ?? '');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        if ($this->request->isAjax()) {
            $this->jsonError($result['message'] ?? 'خطا در لاگین با فیسبوک');
            return;
        }
        $this->session->setFlash('error', $result['message'] ?? 'خطا در لاگین با فیسبوک');
        $this->response->redirect(url('login'));
        return;
    }

    /**
     * لیست حساب‌های اجتماعی متصل
     */
    public function listAccounts(): void
    {
        $this->requireAuth();
        $this->requirePermission('user.manage_social_accounts');

        $userId = $this->userId();
        $accounts = $this->oauthService->getLinkedAccounts($userId);
        
        $this->jsonSuccess('', ['accounts' => $accounts]);
    }

    /**
     * اتصال حساب جدید
     */
    public function linkAccount(): void
    {
        $this->requireAuth();
        $this->requirePermission('user.manage_social_accounts');

        $provider = (string)$this->request->post('provider');

        if (empty($provider)) {
            $this->jsonError('انتخاب سرویس‌دهنده الزامی است');
            return;
        }

        // CRIT-05 Fix: Redirect to OAuth flow instead of accepting user_data directly
        $url = $this->oauthService->getAuthUrlForLinking($provider, (int)$this->userId());
        
        if ($this->request->isAjax()) {
            $this->jsonSuccess('Redirecting to ' . $provider, ['redirect' => $url]);
            return;
        }
        
        $this->response->redirect($url);
    }

    /**
     * قطع اتصال حساب
     */
    public function unlinkAccount(): void
    {
        $this->requireAuth();
        $this->requirePermission('user.manage_social_accounts');

        $provider = (string)$this->request->post('provider');
        if (empty($provider)) {
            $this->jsonError('انتخاب سرویس‌دهنده الزامی است');
            return;
        }

        $userId = $this->userId();
        // بررسی محدودیت‌های حذف (اختیاری در اینجا، منطق در سرویس است)
        $result = $this->oauthService->unlinkSocialAccount($userId, $provider);

        if ($result['success']) {
            $this->jsonSuccess($result['message'] ?? 'اتصال حساب قطع شد');
            return;
        }
        $this->jsonError($result['message'] ?? 'خطا در قطع اتصال');
    }

    /**
     * نمایش صفحه تأیید رمز عبور برای اتصال OAuth
     */
    public function showConfirmPassword(): void
    {
        $pending = $this->session->get('oauth_pending_link');
        if (!$pending || empty($pending['email']) || empty($pending['provider'])) {
            $this->session->remove('oauth_pending_link');
            $this->response->redirect(url('login'));
            return;
        }

        $this->view('auth/oauth-confirm', [
            'title'    => 'تأیید رمز عبور برای اتصال حساب',
            'email'    => $pending['email'],
            'provider' => $pending['provider']
        ]);
    }

    /**
     * پردازش تأیید رمز عبور و اتصال OAuth
     */
    public function confirmPassword(): void
    {
        $pending = $this->session->get('oauth_pending_link');
        if (!$pending || empty($pending['email']) || empty($pending['provider']) || empty($pending['data'])) {
            $this->session->remove('oauth_pending_link');
            if ($this->request->isAjax()) {
                $this->jsonError('نشست تأیید منقضی یا نامعتبر است');
                return;
            }
            $this->session->setFlash('error', 'نشست تأیید منقضی یا نامعتبر است');
            $this->response->redirect(url('login'));
            return;
        }

        $password = (string)$this->request->post('password');
        if (empty($password)) {
            if ($this->request->isAjax()) {
                $this->jsonError('وارد کردن رمز عبور الزامی است');
                return;
            }
            $this->session->setFlash('error', 'وارد کردن رمز عبور الزامی است');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        // Verify password using AuthService
        $authService = app(\App\Services\Auth\AuthService::class);
        $userModel = app(\App\Models\User::class);
        $user = $userModel->findByEmail($pending['email']);

        if (!$user || !$authService->verifyPassword($password, $user->password, (int)$user->id)) {
            if ($this->request->isAjax()) {
                $this->jsonError('رمز عبور وارد شده اشتباه است');
                return;
            }
            $this->session->setFlash('error', 'رمز عبور وارد شده اشتباه است');
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        // Confirm user status before logging in
        if (in_array($user->status, ['locked', 'banned', 'suspended', 'locked_2fa'], true)) {
            $this->session->remove('oauth_pending_link');
            $msg = 'حساب کاربری شما مسدود، قفل یا غیرفعال شده است.';
            if ($this->request->isAjax()) {
                $this->jsonError($msg);
                return;
            }
            $this->session->setFlash('error', $msg);
            $this->response->redirect(url('login'));
            return;
        }

        // Link the social account
        $linkResult = $this->oauthService->linkSocialAccountSafe((int)$user->id, $pending['provider'], $pending['data']);
        if (!$linkResult['success']) {
            if ($this->request->isAjax()) {
                $this->jsonError($linkResult['message']);
                return;
            }
            $this->session->setFlash('error', $linkResult['message']);
            $this->response->redirect(url('auth/oauth-confirm'));
            return;
        }

        // Clean up pending session variable
        $this->session->remove('oauth_pending_link');

        // Login the user via direct login method (handles sessions, events and 2FA perfectly)
        $loginResult = $authService->loginDirectly($user);
        if (!$loginResult['success']) {
            $msg = $loginResult['message'] ?? 'خطا در ورود به حساب کاربری';
            if ($this->request->isAjax()) {
                $this->jsonError($msg);
                return;
            }
            $this->session->setFlash('error', $msg);
            $this->response->redirect(url('login'));
            return;
        }

        $requires2FA = !empty($loginResult['requires_2fa']);
        $redirectUrl = $requires2FA ? url('verify-2fa') : url('dashboard');
        
        if ($this->request->isAjax()) {
            $this->jsonSuccess('حساب کاربری متصل و ورود موفقیت‌آمیز بود.', ['redirect' => $redirectUrl]);
            return;
        }

        $this->session->setFlash('success', 'حساب کاربری متصل و ورود موفقیت‌آمیز بود.');
        $this->response->redirect($redirectUrl);
    }
}
