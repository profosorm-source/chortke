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
        // CRITICAL-01 Fix: Redundant checkRateLimit removed. AuthService::login now handles 
        // consolidated IP + Identifier rate limiting.

        $email = (string)$this->request->input('email', '');
        $captchaType = $this->loginRiskService->getCaptchaType('login', null, $email);
        if ($captchaType !== null) {
            // ✅ استفاده از $this->request->input() به جای $_POST
            $captchaToken = trim((string)$this->request->input('captcha_token', ''));
            $captchaResp = trim((string)$this->request->input('captcha_response', ''));
            $recaptchaResp = trim((string)$this->request->input('g-recaptcha-response', ''));

            if ($captchaType === 'recaptcha_v2') {
                if ($recaptchaResp === '' || !$this->captchaService->verify('', '', $recaptchaResp)) {
                    $this->loginRiskService->recordFailure('login', null, $email);
                    $this->session->setFlash('error', 'کپچا نامعتبر است.');
                    $this->response->redirect(url('login'));
                    return;
                }
            } else {
                if ($captchaToken === '' || $captchaResp === '' || !$this->captchaService->verify($captchaToken, $captchaResp)) {
                    $this->loginRiskService->recordFailure('login', null, $email);
                    $this->session->setFlash('error', 'کپچا اشتباه است.');
                    $this->response->redirect(url('login'));
                    return;
                }
            }
        }

        $data = $this->request->all();
        $validator = new Validator($data, [
            'email'    => 'required|email',
            'password' => 'required|min:8',
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
            $this->loginRiskService->recordFailure('login', null, (string)$data['email']);
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
            $this->session->set('pending_2fa_user_id', (int)$result['user']->id);
            $this->response->redirect(url('verify-2fa'));
            return;
        }

        // CRITICAL-C1 Fix: Redundant regenerate() removed. AuthService::login already calls regenerate(true)
        // to prevent session fixation and ensure 2FA pending state isolation.
        
        $this->loginRiskService->clearFailures('login', null, (string)$data['email']);
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
        // CRITICAL-01 Fix: Redundant checkRateLimit removed.

        $captchaType = $this->loginRiskService->getCaptchaType('register');
        if ($captchaType !== null) {
            $captchaToken = trim((string)$this->request->input('captcha_token', ''));
            $captchaResp  = trim((string)$this->request->input('captcha_response', ''));
            $recaptchaResp = trim((string)$this->request->input('g-recaptcha-response', ''));

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
        
        // HIGH-H-13 Fix: Store timestamp to enforce 15-minute expiration for pending verification
        $this->session->set('pending_verification_email', $data['email']);
        $this->session->set('pending_verification_at', time());

        $this->session->setFlash('success', 'ثبت‌نام موفق! لطفاً ایمیل خود را تأیید کنید.');
        $this->response->redirect(url('email/verify-code'));
    }

    /**
     * نمایش صفحه وارد کردن کد تأیید ایمیل
     */
    public function showVerifyEmail(): void
    {
        $email = $this->session->get('pending_verification_email');
        if (!$email) {
            $this->response->redirect(url('login'));
            return;
        }

        // بررسی انقضا
        $createdAt = (int)$this->session->get('pending_verification_at', 0);
        if (time() - $createdAt > 900) { // 15 minutes
            $this->session->remove('pending_verification_email');
            $this->session->remove('pending_verification_at');
            $this->session->setFlash('error', 'مهلت زمانی تأیید به پایان رسیده است. لطفاً دوباره ثبت‌نام کنید یا درخواست ارسال مجدد دهید.');
            $this->response->redirect(url('login'));
            return;
        }

        // HIGH-H-07 Fix: Prevent session fixation on pending email verification
        $this->session->regenerate(true);

        $this->view('user/verify-email-code', [
            'title' => 'تأیید ایمیل',
            'email' => $email
        ]);
    }

    /**
     * پردازش کد تأیید ایمیل
     */
    public function verifyEmailByCode(): void
    {
        $email = $this->session->get('pending_verification_email');
        if (!$email) {
            $this->response->redirect(url('login'));
            return;
        }

        // HIGH-H-09 Fix: Rate limiting on email verification code to prevent brute-force
        $ip = $this->request->ip();
        $rateLimitKey = "verify_email:" . hash('sha256', "{$email}:{$ip}");
        
        // HIGH-08 Fix: Using attempt() to increment and check, with session destruction on excessive failures
        $rateLimitId = "verify_email_attempts:" . hash('sha256', $email);
        if (!$this->rateLimiter->attempt($rateLimitId, 5, 15)) {
             $this->logger->critical('auth.email_verification.bruteforce_detected', ['email' => $email, 'ip' => $ip]);
             $this->session->destroy();
             $this->session->setFlash('error', 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. نشست شما برای امنیت بیشتر بسته شد.');
             $this->response->redirect(url('login'));
             return;
        }

        $code = trim((string)$this->request->post('code', ''));
        if (strlen($code) !== 6) {
            $this->session->setFlash('error', 'کد وارد شده باید ۶ رقم باشد.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        $user = $this->userService->findByEmail($email);
        if (!$user || empty($user->email_verification_token)) {
            // HIGH-05 Fix: Standardize response to prevent enumeration
            $this->session->setFlash('error', 'کد نامعتبر است یا منقضی شده.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        $inputCode = strtoupper($code);
        $hashedInput = hash_hmac('sha256', $inputCode, (string)config('app.key'));
        
        $isValid = hash_equals((string)$user->email_verification_token, $hashedInput);

        if (!$isValid) {
            $this->logger->warning('auth.email_verification.failed', ['email' => $email, 'ip' => $ip]);
            $this->session->setFlash('error', 'کد نامعتبر است یا منقضی شده.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        // تایید موفق
        $this->userService->verifyEmail((int)$user->id);
        $this->session->remove('pending_verification_email');
        $this->session->remove('pending_verification_at');

        $this->session->setFlash('success', 'ایمیل شما با موفقیت تأیید شد. اکنون می‌توانید وارد شوید.');
        $this->response->redirect(url('login'));
    }

    /**
     * ارسال مجدد ایمیل تأیید
     */
    public function resendVerification(): void
    {
        $email = $this->session->get('pending_verification_email');
        $genericMsg = 'در صورت وجود حساب، ایمیل ارسال شد.';
        
        if (!$email) {
            $this->jsonSuccess($genericMsg);
            return;
        }

        // محدودیت زمانی برای ارسال مجدد (مثلاً هر ۲ دقیقه)
        $ip = $this->request->ip();
        $rateLimitKey = "resend_email:" . hash('sha256', "{$email}:{$ip}");
        
        if (!$this->authService->checkRateLimit('resend_email', $rateLimitKey)) {
            $this->jsonError('لطفاً چند دقیقه صبر کنید و سپس دوباره تلاش کنید.');
            return;
        }

        $user = $this->userService->findByEmail($email);
        
        // HIGH-H-08 Fix: Rotate verification token on resend to prevent use of leaked tokens
        if ($user && empty($user->email_verified_at)) {
            $newToken = bin2hex(random_bytes(32));
            $hashedToken = hash_hmac('sha256', strtoupper(substr($newToken, 0, 6)), (string)config('app.key'));
            
            $this->userService->update((int)$user->id, ['email_verification_token' => $hashedToken]);
            
            app(\App\Services\EmailService::class)->sendVerificationEmail((int)$user->id, $newToken);
            $this->session->set('pending_verification_at', time());
        }

        // Always return success to prevent enumeration
        $this->jsonSuccess($genericMsg);
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
        $email = (string)$this->request->input('email', '');
        $ip = $this->request->ip();
        $genericMsg = 'در صورت وجود حساب، لینک بازیابی ارسال شد.';

        // CRITICAL-03 Fix: Combined IP + Email rate limiting using non-exception pattern
        $emailKey = hash('sha256', mb_strtolower(trim($email)));
        $rateLimitIp = "forgot_pwd_ip:" . hash('sha256', $ip);
        $rateLimitEmail = "forgot_pwd_email:{$emailKey}";

        $rateLimiter = app(\Core\RateLimiter::class);
        if (!$rateLimiter->attempt($rateLimitIp, 5, 60) || 
            !$rateLimiter->attempt($rateLimitEmail, 3, 3600)) {
            
            $this->session->setFlash('error', 'تعداد درخواست‌های بازیابی بیش از حد مجاز است. لطفاً بعداً تلاش کنید.');
            $this->response->redirect(url('forgot-password'));
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Anti-enumeration: still show success but don't process
            $this->session->setFlash('success', $genericMsg);
            $this->response->redirect(url('login'));
            return;
        }

        $result = $this->authService->requestPasswordReset($email);
        $this->session->setFlash('success', $genericMsg);
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

        // HIGH-06 Fix: Prevent password reset token leakage in Referer header
        $this->response->setHeader('Referrer-Policy', 'no-referrer');
        
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
        // MED-02 Fix: Enforce POST + CSRF for logout
        if (!$this->request->isPost()) {
            $this->response->redirect(url('dashboard'));
            return;
        }
        
        try {
            app(\Core\CSRF::class)->validate();
        } catch (\Throwable $e) {
            $this->logger->warning('auth.logout.csrf_failed', [
                'ip' => $this->request->ip(),
                'error' => $e->getMessage()
            ]);
            $this->session->setFlash('error', 'درخواست نامعتبر (CSRF).');
            $this->response->redirect(url('dashboard'));
            return;
        }

        // HIGH-01 Fix: Verify session owner and support logout_all
        $userId = (int)$this->session->get(SessionKeys::USER_ID, 0);
        if ($userId <= 0) {
            $this->response->redirect(url('login'));
            return;
        }

        if ($this->request->post('logout_all') === '1') {
            $this->authService->logoutAll($userId);
        } else {
            $this->authService->logout();
        }

        $this->session->setFlash('success', 'با موفقیت خارج شدید.');
        $this->response->redirect(url('login'));
    }
}