<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Auth\OAuthService;
use Core\Request;
use Core\Response;

/**
 * OAuthController — Social Login (Google + Facebook)
 */
class OAuthController extends BaseController
{
    private OAuthService $oauthService;

    public function __construct(OAuthService $oauthService)
    {
        parent::__construct();
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
            // ورود کاربر به سیستم
            $this->session->set('user_id', $result['user_id']);
            $this->session->set('user_role', $result['role'] ?? 'user');
            
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
            $this->session->set('user_id', $result['user_id']);
            $this->session->set('user_role', $result['role'] ?? 'user');
            
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
        $userData = $this->request->post('user_data');

        if (empty($provider) || empty($userData)) {
            $this->jsonError('پارامترهای ارسالی نامعتبر است');
            return;
        }

        $result = $this->oauthService->linkSocialAccount($this->userId(), $provider, $userData);

        if ($result['success']) {
            $this->jsonSuccess($result['message'] ?? 'حساب با موفقیت متصل شد');
            return;
        }
        $this->jsonError($result['message'] ?? 'خطا در اتصال حساب');
        return;
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
}
