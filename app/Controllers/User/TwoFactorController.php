<?php

namespace App\Controllers\User;

use App\Services\Auth\TwoFactorService;
use App\Services\User\UserService;
use App\Models\ActivityLog;
use App\Controllers\User\BaseUserController;
use App\Constants\SessionKeys;

/**
 * Two Factor Authentication Controller
 */
class TwoFactorController extends BaseUserController
{
    private TwoFactorService $twoFactorService;
    private ActivityLog $activityLog;
    private \Core\RateLimiter $rateLimiter;

    public function __construct(
        ActivityLog $activityLog,
        TwoFactorService $twoFactorService,
        \Core\RateLimiter $rateLimiter
    ) {
        parent::__construct();
        $this->activityLog      = $activityLog;
        $this->twoFactorService = $twoFactorService;
        $this->rateLimiter      = $rateLimiter;
    }

    public function index(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->response->redirect(url('login'));
            return;
        }

        $user = $this->userService->find($userId);
        if (!$user) {
            $this->response->redirect(url('login'));
            return;
        }

        $data = [
            'title'      => 'احراز هویت دو مرحله‌ای',
            'is_enabled' => ($user->two_factor_enabled ?? 0) == 1,
        ];

        if (!$data['is_enabled']) {
            // HIGH-01 Fix: Require password re-verification before showing 2FA secret
            // CRITICAL-C-01 Fix: Implementing 10-minute expiration for 2FA setup authorization
            $authTime = (int)$this->session->get(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED);
            if (!$authTime || (time() - $authTime) > 600) {
                $this->session->remove(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED);
                $this->view('user/security/confirm-password', [
                    'title' => 'تأیید رمز عبور',
                    'redirect_to' => url('security/two-factor')
                ]);
                return;
            }

            if (empty($user->two_factor_secret)) {
                $secret = $this->twoFactorService->generateSecret();
                $encryptedSecret = $this->twoFactorService->encryptSecret($secret);
                $this->userService->update($user->id, ['two_factor_secret' => $encryptedSecret]);
                $user->two_factor_secret = $encryptedSecret;
            }

            // HIGH-H2 Fix: Do not expose the plain secret to the view
            $data['qr_code_url']  = url('two-factor/qr');
        }

        $this->view('user/security/two-factor', $data);
    }

    /**
     * تأیید رمز عبور برای دسترسی به تنظیمات حساس 2FA
     */
    public function authorizeSetup(): void
    {
        $password = (string)$this->request->post('password');
        $userId = $this->userId();
        
        if (!$userId) {
            $this->jsonError('لطفاً وارد شوید.', [], 401);
            return;
        }

        // Rate limit password attempts
        $throttleKey = 'pw_confirm:' . $userId . ':' . $this->request->ip();
        if (!$this->rateLimiter->attempt($throttleKey, 5, 1)) {
            $this->jsonError('تعداد تلاش‌های شما بیش از حد مجاز است.', [], 429);
            return;
        }

        $user = $this->userService->find($userId);
        if ($user && verify_user_password($password, $user->password, (int)$user->id)) {
            // CRITICAL-C-01 Fix: Store timestamp instead of boolean for expiration check
            $this->session->set(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED, time());
            
            // HIGH-H-05 Fix: Regenerate session after password verification to prevent session fixation before sensitive 2FA setup
            $this->session->regenerate(true);

            $this->rateLimiter->clear($throttleKey);
            $this->jsonSuccess('تأیید شد', ['redirect' => url('security/two-factor')]);
            return;
        }

        $this->jsonError('رمز عبور اشتباه است.');
    }

    public function showVerify(): void
    {
        $userId = $this->session->get(SessionKeys::PENDING_2FA_USER_ID);
        if (!$userId) {
            $this->response->redirect(url('login'));
            return;
        }

        $this->view('user/security/verify-2fa', [
            'title' => 'تأیید هویت دو مرحله‌ای',
        ]);
    }

    public function verify(): void
    {
        $userId = $this->session->get(SessionKeys::PENDING_2FA_USER_ID);
        if (!$userId) {
            if ($this->request->isAjax()) {
                $this->jsonError('نشست نامعتبر است.', [], 401);
                return;
            }
            $this->response->redirect(url('login'));
            return;
        }

        // H21 Fix: محافظت ضد Brute-Force برای کدهای 2FA
        $throttleKey = '2fa_verify:' . $userId . ':' . $this->request->ip();
        if (!$this->rateLimiter->attempt($throttleKey, 5, 1)) { // حداکثر 5 تلاش در دقیقه
            $this->response->json([
                'success' => false, 
                'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است. لطفاً یک دقیقه صبر کنید.'
            ]);
            return;
        }

        $code = trim((string)($this->request->input('code') ?? ''));
        if ($code === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً کد را وارد کنید.']);
            return;
        }

        $user = $this->userService->find((int)$userId);
        if (!$user || empty($user->two_factor_secret) || !$user->two_factor_enabled) {
            // CRIT-05 Fix: Ensure 2FA is actually enabled and secret exists. Atomic cleanup on failure.
            $this->session->destroy();
            $this->response->json(['success' => false, 'message' => 'خطا در احراز هویت.'], 401);
            return;
        }

        if ($this->twoFactorService->verifyCode($user->two_factor_secret, $code, (int)$userId)) {
            $this->rateLimiter->clear($throttleKey);

            $this->authService->finalizeSessionAfter2FA($user);

            $this->logger->activity('2fa.verified', 'تأیید موفق احراز هویت دو مرحله‌ای', $user->id, [
                'channel' => 'auth',
            ]);
            
            $this->response->json([
                'success'  => true,
                'message'  => 'ورود موفقیت‌آمیز بود.',
                'redirect' => url('dashboard'),
            ]);
            return;
        }

        $this->userService->incrementFraudScore((int)$userId, 5);
        $this->response->json(['success' => false, 'message' => 'کد وارد شده نامعتبر است.']);
    }

    public function enable(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->jsonError('لطفاً وارد شوید.', [], 401);
            return;
        }

        // CRIT-04 Fix: Rate limiting on 2FA enablement
        $throttleKey = '2fa_enable:' . $userId . ':' . $this->request->ip();
        if (!$this->rateLimiter->attempt($throttleKey, 5, 1)) {
            $this->response->json([
                'success' => false,
                'message' => 'تعداد تلاش‌های شما برای فعال‌سازی بیش از حد مجاز است. لطفاً یک دقیقه صبر کنید.'
            ], 429);
            return;
        }

        $code = trim((string)($this->request->input('code') ?? ''));
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            $this->response->json(['success' => false, 'message' => 'لطفاً کد ۶ رقمی را وارد کنید.']);
            return;
        }

        $result = $this->twoFactorService->enable($userId, $code);

        if ($result['success']) {
            $this->session->remove(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED);
            
            // HIGH-H-05 Fix: Regenerate session after enabling 2FA to ensure a clean, secure session state
            $this->session->regenerate(true);

            $this->logger->activity('2fa.enabled', 'فعال‌سازی احراز هویت دو مرحله‌ای', $userId, [
                'channel' => 'auth',
            ]);
        }

        $this->response->json($result);
    }

    /**
     * HIGH-H2 Fix: Server-side QR Code Generator Proxy
     * This prevents leaking the TOTP secret via Referrer headers or browser history/logs.
     */
    public function qrCode(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->response->setStatusCode(401);
            $this->response->setContent('Unauthorized');
            return;
        }

        $user = $this->userService->find($userId);
        if (!$user || empty($user->two_factor_secret) || ($user->two_factor_enabled && !$this->session->get(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED))) {
            $this->response->setStatusCode(404);
            $this->response->setContent('Not Found');
            return;
        }

        $otpAuthUrl = $this->twoFactorService->getQRCodeUrl(
            $user->username ?? $user->email,
            $user->two_factor_secret
        );

        // Render QR locally using the internal QRCode library
        try {
            $svg = \Core\Lib\QRCode::svg($otpAuthUrl);
            
            $this->response->header('Content-Type', 'image/svg+xml');
            $this->response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
            $this->response->header('Pragma', 'no-cache');
            $this->response->setContent($svg);
        } catch (\Throwable $e) {
            $this->logger->error('2fa.qr_generation.failed', ['error' => $e->getMessage()]);
            $this->response->setStatusCode(500);
            $this->response->setContent('Internal Server Error: QR Generation failed');
        }
    }

    public function disable(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->jsonError('لطفاً وارد شوید.', [], 401);
            return;
        }

        $password = (string)($this->request->input('password') ?? '');
        if ($password === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً رمز عبور خود را وارد کنید.']);
            return;
        }

        $result = $this->twoFactorService->disable($userId, $password);

        if ($result['success']) {
            $this->logger->activity('2fa.disabled', 'غیرفعال‌سازی احراز هویت دو مرحله‌ای', $userId, [
                'channel' => 'auth',
            ]);
        }

        $this->response->json($result);
    }
}