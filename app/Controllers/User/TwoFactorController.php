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
            if (!$this->session->get(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED)) {
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

            $data['secret']       = $this->twoFactorService->decryptSecret($user->two_factor_secret);
            $data['qr_code_url']  = $this->twoFactorService->getQRCodeUrl(
                $user->username ?? $user->email,
                $user->two_factor_secret
            );
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
        if ($user && password_verify($password, $user->password)) {
            $this->session->set(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED, true);
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
        if (!$user || empty($user->two_factor_secret)) {
            $this->response->json(['success' => false, 'message' => 'خطا در احراز هویت.']);
            return;
        }

        if ($this->twoFactorService->verifyCode($user->two_factor_secret, $code, (int)$userId)) {
            $this->rateLimiter->clear($throttleKey);

            $this->session->remove(SessionKeys::PENDING_2FA_USER_ID);
            
            // CRIT-03 Fix: regenerate(true) BEFORE setting sensitive session data
            $this->session->regenerate(true); 

            $this->session->set(SessionKeys::USER_ID,   $user->id);
            $this->session->set(SessionKeys::USERNAME,  $user->username  ?? '');
            $this->session->set(SessionKeys::USER_EMAIL, $user->email);
            $this->session->set(SessionKeys::USER_ROLE, $user->role); 
            $this->session->set(SessionKeys::IS_ADMIN,  in_array($user->role, ['admin', 'super_admin'], true));
            $this->session->set(SessionKeys::LOGGED_IN, true);

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
            $this->logger->activity('2fa.enabled', 'فعال‌سازی احراز هویت دو مرحله‌ای', $userId, [
                'channel' => 'auth',
            ]);
        }

        $this->response->json($result);
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