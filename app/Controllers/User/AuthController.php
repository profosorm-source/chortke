<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\User\UserService;
use App\Services\Auth\AuthService;
use Core\Validator;
use App\Controllers\BaseController;
use App\Services\Auth\LoginRiskService;

/**
 * AuthController
 * 
 * مدیریت فرآیندهای احراز هویت (ورود، ثبت‌نام، فراموشی رمز عبور).
 */
class AuthController extends BaseController
{
    public function __construct(
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        private UserService $userService,
        private \App\Services\CaptchaService $captchaService,
        private AuthService $authService,
        private LoginRiskService $loginRiskService,
        private \App\Services\AntiFraud\FraudGuardService $fraudGuard
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger);
    }

    /**
     * نمایش فرم ورود
     */
    public function showLogin(): void
    {
        $captchaType = $this->loginRiskService->getCaptchaType('login');
        $this->view('user/login', [
            'title'       => 'ورود به سیستم',
            'captchaType' => $captchaType,
        ]);
    }

    /**
     * پردازش ورود
     */
    public function login(): void
    {
        try {
            $this->authService->checkRateLimit('auth', 'login');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                $this->response->redirect(url('login'));
                return;
            }
        }

        $captchaType = $this->loginRiskService->getCaptchaType('login');
        if ($captchaType !== null) {
            // ✅ استفاده از $this->request->input() به جای $_POST
            $captchaToken = trim((string)$this->request->input('captcha_token', ''));
            $captchaResp = trim((string)$this->request->input('captcha_response', ''));
            $recaptchaResp = trim((string)$this->request->input('g-recaptcha-response', ''));

            if ($captchaType === 'recaptcha_v2') {
                if ($recaptchaResp === '' || !$this->captchaService->verify('', '', $recaptchaResp)) {
                    $this->loginRiskService->recordFailure('login');
                    $this->session->setFlash('error', 'کپچا نامعتبر است.');
                    $this->response->redirect(url('login'));
                    return;
                }
            } else {
                if ($captchaToken === '' || $captchaResp === '' || !$this->captchaService->verify($captchaToken, $captchaResp)) {
                    $this->loginRiskService->recordFailure('login');
                    $this->session->setFlash('error', 'کپچا اشتباه است.');
                    $this->response->redirect(url('login'));
                    return;
                }
            }
        }

        $data = $this->request->all();
        $validator = new Validator($data, [
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', 'لطفاً اطلاعات را به درستی وارد کنید.');
            $this->response->redirect(url('login'));
            return;
        }

        // 🛡️ گیت ضدتقلب و امنیت هوشمند
        $user = $this->userService->findByEmail((string)$data['email']);
        $userId = $user ? (int)$user->id : 0;

        $risk = $this->fraudGuard->checkAction($userId, 'auth.login', [
            'email'      => (string)$data['email'],
            'ip'         => $this->request->ip(),
            'user_agent' => $this->request->userAgent()
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('auth.login_blocked_by_fraud_guard', [
                'email' => $data['email'],
                'reason' => $risk['reason']
            ]);
            $this->session->setFlash('error', 'درخواست ورود به دلیل تشخیص فعالیت غیرمجاز مسدود گردید.');
            $this->response->redirect(url('login'));
            return;
        }

        $remember = ($data['remember'] ?? '') === 'on';
        $result = $this->authService->login($data['email'], $data['password'], $remember);

        if (!$result['success']) {
            $this->loginRiskService->recordFailure('login');
            if (!empty($result['email_unverified'])) {
                $this->session->set('pending_verification_email', $result['email']);
                $this->session->setFlash('success', 'ایمیل تأیید ارسال شد.');
                $this->response->redirect(url('email/verify-code'));
                return;
            }
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('login'));
            return;
        }

        if (!empty($result['requires_2fa'])) {
            $this->session->set('pending_2fa_user', (int)$result['user']->id);
            $this->response->redirect(url('verify-2fa'));
            return;
        }

        $this->loginRiskService->clearFailures('login');
        $this->session->setFlash('success', 'خوش آمدید!');
        $this->response->redirect(url('dashboard'));
    }

    /**
     * نمایش فرم ثبت‌نام
     */
    public function showRegister(): void
    {
        $ref = $this->request->query('ref', '');
        if ($ref && preg_match('/^[A-Za-z0-9_]{4,32}$/', (string)$ref)) {
            $this->session->set('register_referral_code', $ref);
        }

        $this->view('user/register', [
            'referralCode' => $this->session->get('register_referral_code'),
            'captchaType'  => $this->loginRiskService->getCaptchaType('register'),
        ]);
    }

    /**
     * پردازش ثبت‌نام
     */
    public function register(): void
    {
        try {
            $this->authService->checkRateLimit('auth', 'register');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                $this->response->redirect(url('register'));
                return;
            }
        }

        $captchaType = $this->loginRiskService->getCaptchaType('register');
        if ($captchaType !== null) {
            $captchaToken = trim((string)($_POST['captcha_token'] ?? ''));
            $captchaResp  = trim((string)($_POST['captcha_response'] ?? ''));
            $recaptchaResp = trim((string)($_POST['g-recaptcha-response'] ?? ''));

            if ($captchaType === 'recaptcha_v2') {
                if ($recaptchaResp === '' || !$this->captchaService->verify('', '', $recaptchaResp)) {
                    $this->loginRiskService->recordFailure('register');
                    $this->session->setFlash('error', 'کپچا نامعتبر است.');
                    $this->response->redirect(url('register'));
                    return;
                }
            } else {
                if ($captchaToken === '' || $captchaResp === '' || !$this->captchaService->verify($captchaToken, $captchaResp)) {
                    $this->loginRiskService->recordFailure('register');
                    $this->session->setFlash('error', 'کپچا اشتباه است.');
                    $this->response->redirect(url('register'));
                    return;
                }
            }
        }

        $data = $this->request->all();
        $errors = $this->authService->validateRegister($data);
        if (!empty($errors)) {
            $this->session->setFlash('error', implode('<br>', $errors));
            $this->response->redirect(url('register'));
            return;
        }

        // 🛡️ گیت ضدتقلب و امنیت ثبت‌نام (شناسایی ربات‌ها، ایمیل‌های یک‌بار مصرف و مخرب)
        $risk = $this->fraudGuard->checkAction(0, 'auth.register', [
            'email'      => (string)($data['email'] ?? ''),
            'phone'      => (string)($data['mobile'] ?? ''),
            'ip'         => $this->request->ip(),
            'user_agent' => $this->request->userAgent()
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('auth.registration_blocked_by_fraud_guard', [
                'email'  => $data['email'] ?? 'unknown',
                'reason' => $risk['reason']
            ]);
            $this->session->setFlash('error', 'امکان ثبت‌نام به دلیل تشخیص رفتارهای مشکوک مسدود گردید.');
            $this->response->redirect(url('register'));
            return;
        }

        $result = $this->authService->register($data);
        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('register'));
            return;
        }

        $this->session->remove('register_referral_code');
        $this->session->set('pending_verification_email', $data['email']);
        $this->session->setFlash('success', 'ثبت‌نام موفق! لطفاً ایمیل خود را تأیید کنید.');
        $this->response->redirect(url('email/verify-code'));
    }

    /**
     * نمایش فرم فراموشی رمز عبور
     */
    public function showForgotPassword(): void
    {
        $this->view('auth/forgot-password', ['title' => 'فراموشی رمز عبور']);
    }

    /**
     * پردازش فراموشی رمز عبور
     */
    public function forgotPassword(): void
    {
        try {
            $this->authService->checkRateLimit('auth', 'forgot_password');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                $this->response->redirect(url('forgot-password'));
                return;
            }
        }

        $email = $this->request->input('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->setFlash('error', 'ایمیل معتبر وارد کنید.');
            $this->response->redirect(url('forgot-password'));
            return;
        }

        $result = $this->authService->requestPasswordReset((string)$email);
        $this->session->setFlash('success', $result['message']);
        $this->response->redirect(url('login'));
    }

    /**
     * نمایش فرم تنظیم مجدد رمز عبور
     */
    public function showResetPassword(): void
    {
        $token = $this->request->get('token');
        if (!$token) {
            $this->response->redirect(url('login'));
            return;
        }
        $this->view('auth/reset-password', ['token' => $token]);
    }

    /**
     * پردازش تنظیم مجدد رمز عبور
     */
    public function resetPassword(): void
    {
        $data = $this->request->all();
        $validator = new Validator($data, [
            'token'            => 'required',
            'password'         => 'required|min:8',
            'password_confirm' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', 'رمز عبور معتبر وارد کنید.');
            $this->response->redirect(url('reset-password?token=' . ($data['token'] ?? '')));
            return;
        }

        $result = $this->authService->resetPassword((string)$data['token'], (string)$data['password']);
        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('forgot-password'));
            return;
        }

        $this->session->setFlash('success', 'رمز عبور با موفقیت تغییر یافت.');
        $this->response->redirect(url('login'));
    }

    /**
     * خروج از سیستم
     */
    public function logout(): void
    {
        $this->authService->logout();
        $this->session->setFlash('success', 'با موفقیت خارج شدید.');
        $this->response->redirect(url('login'));
    }
}