<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\SecurityModel;
use App\Models\User;
use App\Services\User\UserService;
use App\Services\EmailService;
use App\Services\BaseService;
use App\Services\AuditTrail;
use Core\Logger;
use Core\Session;
use Core\RateLimiter;

/**
 * AuthService
 *
 * هماهنگ‌کننده اصلی احراز هویت.
 */
class AuthService extends \App\Services\BaseService
{
    public function __construct(
        Logger $logger,
        private UserService $userService,
        private User $userModel,
        private SecurityModel $securityModel,
        private Session $session,
        private RateLimiter $rateLimiter,
        private SessionService $sessionService,
        private AuditTrail $auditTrail,
        private ?EmailService $emailService = null
    ) {
        parent::__construct($logger);
    }

    /**
     * بررسی محدودیت نرخ درخواست برای ورود امن
     */
    public function checkRateLimit(string $action, string $key): void
    {
        $ip = get_client_ip();
        $rateLimitCheck = $this->rateLimiter->checkLoginAttempt($ip);
        if (isset($rateLimitCheck['allowed']) && !$rateLimitCheck['allowed']) {
            throw new \Exception($rateLimitCheck['message'] ?? 'تعداد درخواست بیش از حد مجاز است.', 429);
        }
    }

    public function login(string $identifier, string $password, bool $remember = false): array
    {
        $rateLimitCheck = $this->rateLimiter->checkLoginAttempt($identifier);
        if (!$rateLimitCheck['allowed']) {
            return ['success' => false, 'message' => $rateLimitCheck['message']];
        }

        $user = $this->userModel->findByCredentials($identifier);
        if (!$user || !verify_password($password, $user->password)) {
            $this->logger->warning('auth.login.failed', ['identifier' => $identifier]);
            if ($user) $this->userModel->incrementFraudScore((int)$user->id, 5);
            return ['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است.'];
        }

        if ($user->status === 'banned' || $user->status === 'suspended') {
            return ['success' => false, 'message' => 'حساب کاربری شما مسدود یا تعلیق شده است.'];
        }

        if (empty($user->email_verified_at)) {
            return ['success' => false, 'message' => 'ایمیل شما هنوز تأیید نشده است.', 'email_unverified' => true, 'email' => $user->email];
        }

        $this->rateLimiter->clearLoginAttempts($identifier);
        $this->createSession($user, $remember);

        $this->logger->activity('auth.login', 'ورود موفق کاربر', (int)$user->id);
        $this->auditTrail->record('auth.login', (int)$user->id, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        
        return [
            'success'      => true,
            'message'      => 'ورود موفقیت‌آمیز بود.',
            'user'         => $user,
            'requires_2fa' => (bool)($user->two_factor_enabled ?? false),
        ];
    }

    private function createSession(object $user, bool $remember = false): void
    {
        $this->session->regenerate();
        $this->session->set('user_id',  (int)$user->id);
        $this->session->set('username', $user->username ?? '');
        $this->session->set('role',     $user->role);
        $this->session->set('is_admin', in_array($user->role, ['admin', 'super_admin'], true));
        $this->session->set('logged_in', true);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $this->userModel->update((int)$user->id, ['remember_token' => hash('sha256', $token)]);
            setcookie('remember_token', $token, time() + (30 * 86400), '/', '', true, true);
        }

        // ✅ Pass HTTP data explicitly (extracted from HTTP Layer)
        $this->sessionService->recordSession(
            userId: (int)$user->id,
            sessionId: $this->session->getId(),
            userAgent: get_user_agent(),
            ipAddress: get_client_ip(),
            acceptLanguage: $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            acceptEncoding: $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        );
    }

    public function logout(): void
    {
        $userId = $this->session->get('user_id');
        if ($userId) {
            $this->logger->activity('auth.logout', 'خروج کاربر', (int)$userId);
        }

        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
        }

        $this->session->destroy();
    }

    public function requestPasswordReset(string $email): array
    {
        $user = $this->userModel->findByEmail($email);
        $genericMsg = 'اگر این ایمیل در سیستم ثبت شده باشد، لینک بازیابی برای شما ارسال می‌شود.';

        if (!$user) return ['success' => true, 'message' => $genericMsg];

        $token = bin2hex(random_bytes(32));
        $this->securityModel->createPasswordResetToken($email, $token);
        
        if ($this->emailService) {
            $this->emailService->sendPasswordResetEmail((int)$user->id, $token);
        }

        $this->logger->activity('auth.password_reset.requested', 'درخواست بازیابی رمز عبور', (int)$user->id);
        return ['success' => true, 'message' => $genericMsg];
    }

    public function resetPassword(string $token, string $newPassword): array
    {
        $record = $this->securityModel->findPasswordResetByToken($token);
        if (!$record || (time() - strtotime((string)$record->created_at)) > 3600) {
            return ['success' => false, 'message' => 'لینک بازیابی نامعتبر یا منقضی شده است.'];
        }

        $user = $this->userModel->findByEmail($record->email);
        if (!$user) return ['success' => false, 'message' => 'کاربر یافت نشد.'];

        $this->userService->changePassword((int)$user->id, $newPassword);
        $this->securityModel->deletePasswordResetByEmail($record->email);

        $this->logger->activity('auth.password_reset.completed', 'بازیابی رمز عبور انجام شد', (int)$user->id);
        return ['success' => true, 'message' => 'رمز عبور با موفقیت تغییر کرد.'];
    }

    public function check(): bool
    {
        return $this->session->get('logged_in') === true;
    }

    public function user(): ?object
    {
        if (!$this->check()) return null;
        return $this->userModel->find((int)$this->session->get('user_id'));
    }
}
