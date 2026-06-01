<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\User\UserService;
use App\Services\Auth\AuthService;
use App\Controllers\BaseController;
use App\Services\Auth\LoginRiskService;
use App\Validators\LoginRequest;

/**
 * AuthController
 * 
 * مدیریت فرآیندهای احراز هویت (ورود، ثبت‌نام، فراموشی رمز عبور).
 * 
 * SECURITY NOTES:
 * - User enumeration is prevented with constant-time responses
 * - Rate limiting on all authentication endpoints
 * - Session isolation for different auth states
 */
class AuthController extends BaseController
{
    private UserService $userService;
    private \App\Services\CaptchaService $captchaService;
    private AuthService $authService;
    private LoginRiskService $loginRiskService;
    private \App\Services\AntiFraud\FraudGuardService $fraudGuard;
    private \Core\RateLimiter $rateLimiter;
    private \App\Services\EmailService $emailService;
    private \App\Models\SecurityModel $securityModel;
    public function __construct(
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        UserService $userService,
        \App\Services\CaptchaService $captchaService,
        AuthService $authService,
        LoginRiskService $loginRiskService,
        \App\Services\AntiFraud\FraudGuardService $fraudGuard,
        \Core\RateLimiter $rateLimiter,
        \App\Services\EmailService $emailService,
        \App\Models\SecurityModel $securityModel
    ) {        $this->userService = $userService;
        $this->captchaService = $captchaService;
        $this->authService = $authService;
        $this->loginRiskService = $loginRiskService;
        $this->fraudGuard = $fraudGuard;
        $this->rateLimiter = $rateLimiter;
        $this->emailService = $emailService;
        $this->securityModel = $securityModel;

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

        $data = $this->request->all();
        $email = mb_strtolower(trim((string)($data['email'] ?? '')), 'UTF-8');
        $captchaType = $this->loginRiskService->getCaptchaType('login', null, $email);
        if ($captchaType !== null) {
            // ✅ استفاده از $this->request->input() به جای $_POST
            $captchaToken = trim((string)($data['captcha_token'] ?? ''));
            $captchaResp = trim((string)($data['captcha_response'] ?? ''));
            $recaptchaResp = trim((string)($data['g-recaptcha-response'] ?? ''));
            $behavioralState = trim((string)($data['behavioral_state'] ?? ''));

            if ($captchaType === 'recaptcha_v2') {
                if ($recaptchaResp === '' || !$this->captchaService->verify('', '', $recaptchaResp)) {
                    $this->loginRiskService->recordFailure('login', null, $email);
                    $this->session->setFlash('error', 'کپچا نامعتبر است.');
                    $this->response->redirect(url('login'));
                    return;
                }
            } else {
                $isBehavioral = ($captchaType === 'behavioral');
                if ($captchaToken === '' || (!$isBehavioral && $captchaResp === '') || !$this->captchaService->verify($captchaToken, $captchaResp, null, $behavioralState)) {
                    $this->loginRiskService->recordFailure('login', null, $email);
                    $this->session->setFlash('error', 'کپچا اشتباه است.');
                    $this->response->redirect(url('login'));
                    return;
                }
            }
        }

        // اعتبارسنجی ورودی با استفاده از FormRequest
        $loginReq = new LoginRequest($data);
        if ($loginReq->fails() || !$loginReq->validate()) {
            $this->session->setFlash('error', 'لطفاً اطلاعات را به درستی وارد کنید.');
            $this->response->redirect(url('login'));
            return;
        }
        $data = $loginReq->validated();

        // 🛡️ گیت ضدتقلب و امنیت هوشمند
        $user = $this->userService->findByCredentials($email);
        $userId = $user ? (int)$user->id : 0;

        $risk = $this->fraudGuard->checkAction($userId, 'auth.login', [
            'email'      => $email,
            'ip'         => $this->request->ip(),
            'user_agent' => $this->request->userAgent()
        ]);

        if (!$risk['allowed']) {
            $this->logger->warning('auth.login_blocked_by_fraud_guard', [
                'email' => $email,
                'reason' => $risk['reason']
            ]);
            $this->session->setFlash('error', 'درخواست ورود به دلیل تشخیص فعالیت غیرمجاز مسدود گردید.');
            $this->response->redirect(url('login'));
            return;
        }

        $remember = ($data['remember'] ?? '') === 'on';
        $result = $this->authService->login($email, (string)($data['password'] ?? ''), $remember);

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
        $this->csrf->regenerate();
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
            $behavioralState = trim((string)$this->request->input('behavioral_state', ''));

            if ($captchaType === 'recaptcha_v2') {
                if ($recaptchaResp === '' || !$this->captchaService->verify('', '', $recaptchaResp)) {
                    $this->loginRiskService->recordFailure('register');
                    $this->session->setFlash('error', 'کپچا نامعتبر است.');
                    $this->response->redirect(url('register'));
                    return;
                }
            } else {
                $isBehavioral = ($captchaType === 'behavioral');
                if ($captchaToken === '' || (!$isBehavioral && $captchaResp === '') || !$this->captchaService->verify($captchaToken, $captchaResp, null, $behavioralState)) {
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
        
        // CRITICAL-01 Fix: Regenerate session ID immediately after registration
        // to prevent session fixation attacks.
        $this->session->regenerate(true);

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
            $this->session->setFlash('error', 'مهلت زمانی تأیید به پایان رسیده است. لطفاً دوباره ثبت‌نام کنید.');
            $this->response->redirect(url('register'));
            return;
        }

        // CRITICAL-01 Fix: regenerate(true) was moved to register() and resendVerification()
        // to ensure it only happens when the verification state is initialized.

        $this->view('user/verify-email', [
            'title' => 'تأیید ایمیل',
            'email' => $email
        ]);
    }

    /**
     * پردازش کد تأیید ایمیل
     * 
     * HIGH-H-08 Fix: Prevent user enumeration by using constant-time validation
     * and consistent error messages. Rate limiting happens BEFORE user lookup.
     */
    public function verifyEmailByCode(): void
    {
        $email = $this->session->get('pending_verification_email');
        if (!$email) {
            $this->response->redirect(url('login'));
            return;
        }

        $ip = $this->request->ip();
        
        // HIGH-H-08 Fix: Rate limiting on email verification code to prevent brute-force
        // This check happens BEFORE user lookup to prevent timing-based enumeration
        $rateLimitId = "verify_email_attempts:" . hash('sha256', $email);
        
        // HIGH-08 Fix: Using attempt() to increment and check, with session destruction on excessive failures
        if (!$this->rateLimiter->attempt($rateLimitId, 5, 15, true)) {
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

        // HIGH-H-08 Fix: Validate code format (alphanumeric, 6 chars)
        if (!preg_match('/^[A-Z0-9]{6}$/i', $code)) {
            $this->session->setFlash('error', 'کد وارد شده نامعتبر است.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        // MED-01 Fix: Always perform database lookup to maintain consistent timing
        // This prevents timing-based user enumeration
        $user = $this->userService->findByEmail($email);
        
        // HIGH-02 Fix: Timing Leak in verification path for invalid/non-existing users
        // Use a dummy hash comparison when $user or token is null to guarantee constant time hash_equals execution
        $dummyHash = hash_hmac('sha256', 'DUMMY_CODE', secure_key());
        $storedToken = $user && !empty($user->email_verification_token) ? (string)$user->email_verification_token : $dummyHash;
        
        $inputCode = strtoupper($code);
        $hashedInput = hash_hmac('sha256', $inputCode, secure_key());
        
        // Always execute hash_equals for constant time execution
        $isValid = hash_equals($storedToken, $hashedInput) && $user !== null && !empty($user->email_verification_token);

        if (!$isValid) {
            $this->logger->warning('auth.email_verification.failed', [
                'email' => $email, 
                'ip' => $ip,
                'reason' => $user ? 'invalid_code' : 'user_not_found'
            ]);

            // HIGH FIX: Add random delay to prevent timing-based enumeration
            usleep(random_int(50000, 150000));

            // HIGH-H-08 Fix: Standardized error message for all failure cases
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

        // CRITICAL-04 Fix: Using RateLimiter directly to match correct signature and behavior
        $ip = $this->request->ip();
        $rateLimitKey = "resend_email:" . hash('sha256', "{$email}:{$ip}");
        
        if (!$this->rateLimiter->attempt($rateLimitKey, 3, 120, true)) {
            $this->jsonError('لطفاً چند دقیقه صبر کنید و سپس دوباره تلاش کنید.');
            return;
        }

        $user = $this->userService->findByEmail($email);
        
        // HIGH-H-08 Fix: Rotate verification token on resend to prevent use of leaked tokens
        if ($user && empty($user->email_verified_at)) {
            $newToken = bin2hex(random_bytes(32));
            $hashedToken = hash_hmac('sha256', strtoupper(substr($newToken, 0, 6)), secure_key());
            
            $this->userService->update((int)$user->id, ['email_verification_token' => $hashedToken]);
            
            $this->emailService->sendVerificationEmail((int)$user->id, $newToken);
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

        if (!$this->rateLimiter->attempt($rateLimitIp, 5, 60, true) || 
            !$this->rateLimiter->attempt($rateLimitEmail, 3, 3600, true)) {
            
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
            // Check if token is already in session (from previous redirect)
            $token = $this->session->get('pw_reset_token');
        }

        if (!$token) {
            $this->response->redirect(url('login'));
            return;
        }

        // CRITICAL-02 Fix: Move token to session and redirect to remove it from URL
        if ($this->request->get('token')) {
            $this->session->set('pw_reset_token', $token);
            $this->response->redirect(url('reset-password'));
            return;
        }

        // HIGH-02 Fix: Validate token existence and expiry before showing the form
        if (!$this->authService->validatePasswordResetToken((string)$token)) {
            $this->session->remove('pw_reset_token');
            $this->session->setFlash('error', 'لینک بازیابی نامعتبر یا منقضی شده است.');
            $this->response->redirect(url('forgot-password'));
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
        // CRITICAL-02 Fix: Use token from session if missing in request
        if (empty($data['token'])) {
            $data['token'] = $this->session->get('pw_reset_token');
        }

        $validator = $this->validatorFactory()->make($data, [
            'token'            => 'required',
            'password'         => 'required|min:8',
            'password_confirm' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', 'رمز عبور معتبر وارد کنید.');
            $redirectUrl = !empty($data['token']) ? url('reset-password') : url('forgot-password');
            $this->response->redirect($redirectUrl);
            return;
        }

        // CRITICAL-01 Fix: Retrieve bound email directly from the database token record to prevent token confusion
        $timeout = (int)config('auth.password_reset_ttl', 3600);
        $record = $this->securityModel->findPasswordResetByToken((string)$data['token'], $timeout);
        $boundEmail = $record ? $record->email : null;

        $result = $this->authService->resetPassword((string)$data['token'], (string)$data['password'], $boundEmail);
        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('forgot-password'));
            return;
        }

        // Cleanup password reset session keys
        $this->session->remove('pw_reset_token');
        $this->csrf->regenerate();

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
            $this->csrf->validate();
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
            // HIGH-01 Fix: Invalidate all sessions including Redis keys
            $this->authService->logoutAll($userId);
        } else {
            $this->authService->logout();
        }

        $this->session->setFlash('success', 'با موفقیت خارج شدید.');
        $this->response->redirect(url('login'));
    }
}