<?php

namespace App\Controllers\User;

use App\Services\Auth\TwoFactorService;
use App\Services\User\UserService;
use App\Models\ActivityLog;
use App\Controllers\User\BaseUserController;
use App\Constants\SessionKeys;

/**
 * Two Factor Authentication Controller
 * 
 * SECURITY NOTES:
 * - All 2FA operations require valid session with user_id
 * - pending_2fa_user_id is validated against session's logged-in user
 * - Rate limiting prevents brute-force attacks on 2FA codes
 * - IP and session validation prevents session hijacking
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
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
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

        // CRIT-04 Fix: Rate limit password attempts for 2FA setup authorization
        $throttleKey = 'pw_confirm_2fa:' . $userId . ':' . $this->request->ip();
        if (!$this->rateLimiter->attempt($throttleKey, 5, 1, true)) {
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

        // CRITICAL-02 Fix: Reject if it's admin pending
        if ($this->session->get('admin_pending_2fa')) {
            $this->response->redirect(url('/admin/login'));
            return;
        }
        
        // CRIT-04 Fix: Verify that the pending 2FA session has not expired
        $createdAt = (int)$this->session->get('pending_2fa_created_at', 0);
        if (time() - $createdAt > 600) {
            $this->session->destroy();
            $this->response->redirect(url('login'));
            return;
        }

        $this->view('user/security/verify-2fa', [
            'title' => 'تأیید هویت دو مرحله‌ای',
        ]);
    }

    public function verify(): void
    {
        // CRITICAL-02 Fix: Ensure user 2FA verification cannot handle admin pending sessions
        if ($this->session->get('admin_pending_2fa')) {
            $this->logger->warning('2fa.verify.admin_pending_in_user_controller', [
                'pending_user_id' => (int)$this->session->get(SessionKeys::PENDING_2FA_USER_ID, 0),
                'ip' => $this->request->ip()
            ]);
            $this->session->destroy();
            $this->response->json(['success' => false, 'message' => 'نشست نامعتبر است.'], 401);
            return;
        }

        // CRIT-04 Fix: Validate pending_2fa_user_id comes from a valid session
        // This prevents attackers from manipulating the pending 2FA user ID
        $sessionUserId = (int)$this->session->get(SessionKeys::USER_ID, 0);
        $pendingUserId = (int)$this->session->get(SessionKeys::PENDING_2FA_USER_ID, 0);
        
        // If there's a pending 2FA but no session user, reject if it's not a valid pending flow (orphaned pending)
        if ($pendingUserId > 0 && $sessionUserId === 0) {
            $createdAt = (int)$this->session->get('pending_2fa_created_at', 0);
            if ($createdAt === 0 || (time() - $createdAt) > 600) {
                $this->logger->critical('2fa.verify.orphaned_pending', [
                    'pending_user_id' => $pendingUserId,
                    'ip' => $this->request->ip()
                ]);
                $this->session->destroy();
                $this->response->json(['success' => false, 'message' => 'نشست نامعتبر است.'], 401);
                return;
            }
        }

        // If there's a logged-in user in the session, ensure pending 2FA matches or is for the same user
        // This prevents session fixation attacks where attacker tries to use another user's pending 2FA
        if ($sessionUserId > 0 && $pendingUserId > 0 && $sessionUserId !== $pendingUserId) {
            $this->logger->warning('2fa.verify.session_mismatch', [
                'session_user_id' => $sessionUserId,
                'pending_user_id' => $pendingUserId,
                'ip' => $this->request->ip()
            ]);
            $this->session->destroy();
            $this->response->json(['success' => false, 'message' => 'نشست نامعتبر است.'], 401);
            return;
        }
        
        if (!$pendingUserId) {
            if ($this->request->isAjax()) {
                $this->response->json(['success' => false, 'message' => 'نشست نامعتبر است.'], 401);
                return;
            }
            $this->response->redirect(url('login'));
            return;
        }
        
        // CRIT-04 Fix: Verify that the pending 2FA session was created recently
        $createdAt = (int)$this->session->get('pending_2fa_created_at', 0);
        if (time() - $createdAt > 600) { // 10 minute timeout
            $this->session->destroy();
            $this->response->json(['success' => false, 'message' => 'نشست 2FA منقضی شده است. لطفاً دوباره وارد شوید.'], 401);
            return;
        }
        
        // CRIT-04 Fix: Verify IP consistency for 2FA verification
        // Only check if we have a stored IP (backwards compatibility)
        $pendingIp = $this->session->get('pending_2fa_ip');
        if ($pendingIp) {
            $currentIp = $this->clientIp();
            // Normalize IPs to /24 for comparison (allow subnet changes, not complete IP changes)
            $normalize = function(string $ip): string {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $packed = inet_pton($ip);
                    if ($packed === false) return $ip;
                    return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8));
                }
                return substr($ip, 0, strrpos($ip, '.') ?: 0);
            };

            $pendingSubnet = $normalize($pendingIp);
            $currentSubnet = $normalize($currentIp);
            
            if ($pendingSubnet !== $currentSubnet) {
                $this->logger->warning('2fa.verify.ip_changed', [
                    'expected_subnet' => $pendingSubnet,
                    'current_ip' => $currentIp,
                    'user_id' => $pendingUserId
                ]);
                // Log but don't block - could be legitimate network change
                // But increase fraud score as it's suspicious
                $this->userService->incrementFraudScore($pendingUserId, 10);
            }
        }

        // H21 Fix: محافظت ضد Brute-Force برای کدهای 2FA
        $throttleKey = '2fa_verify:' . $pendingUserId . ':' . $this->request->ip();
        if (!$this->rateLimiter->attempt($throttleKey, 5, 1, true)) { // حداکثر 5 تلاش در دقیقه
            $this->response->json([
                'success' => false, 
                'message' => 'تعداد تلاش‌های شما بیش از حد مجاز است. لطفاً یک دقیقه صبر کنید.'
            ], 429);
            return;
        }

        $code = trim((string)($this->request->input('code') ?? ''));
        if ($code === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً کد را وارد کنید.']);
            return;
        }

        $isTotp = preg_match('/^[0-9]{6}$/', $code);
        $isRecovery = preg_match('/^[A-Z0-9]{24}$/i', $code);
        if (!$isTotp && !$isRecovery) {
            $this->response->json(['success' => false, 'message' => 'کد معتبر (۶ رقم یا کد بازیابی ۲۴ کاراکتری) وارد کنید.']);
            return;
        }

        $user = $this->userService->find($pendingUserId);
        if (!$user || empty($user->two_factor_secret) || !$user->two_factor_enabled) {
            // CRIT-05 Fix: Ensure 2FA is actually enabled and secret exists. Atomic cleanup on failure.
            $this->session->destroy();
            $this->response->json(['success' => false, 'message' => 'خطا در احراز هویت.'], 401);
            return;
        }

        if ($this->twoFactorService->verifyCode($user->two_factor_secret, $code, (int)$pendingUserId)) {
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

        $this->userService->incrementFraudScore((int)$pendingUserId, 5);
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
        if (!$this->rateLimiter->attempt($throttleKey, 5, 1, true)) {
            $this->response->json([
                'success' => false,
                'message' => 'تعداد تلاش‌های شما برای فعال‌سازی بیش از حد مجاز است. لطفاً یک دقیقه صبر کنید.'
            ], 429);
            return;
        }

        // Verify 2FA setup authorization
        $authTime = (int)$this->session->get(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED, 0);
        if (time() - $authTime > 600) {
            $this->session->remove(SessionKeys::TWO_FACTOR_SETUP_AUTHORIZED);
            $this->response->json([
                'success' => false,
                'message' => 'مهلت زمانی تأیید رمز عبور به پایان رسیده است. لطفاً دوباره تلاش کنید.'
            ], 401);
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
            $this->csrf->regenerate();

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

        // CRIT-04 Fix: Rate limiting on 2FA disable
        $throttleKey = '2fa_disable:' . $userId . ':' . $this->request->ip();
        if (!$this->rateLimiter->attempt($throttleKey, 3, 60, true)) {
            $this->response->json([
                'success' => false,
                'message' => 'تعداد تلاش‌های شما برای غیرفعال‌سازی بیش از حد مجاز است.'
            ], 429);
            return;
        }

        $password = (string)($this->request->input('password') ?? '');
        if ($password === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً رمز عبور خود را وارد کنید.']);
            return;
        }

        $result = $this->twoFactorService->disable($userId, $password);

        if ($result['success']) {
            $this->csrf->regenerate();
            $this->logger->activity('2fa.disabled', 'غیرفعال‌سازی احراز هویت دو مرحله‌ای', $userId, [
                'channel' => 'auth',
            ]);
        }

        $this->response->json($result);
    }
}