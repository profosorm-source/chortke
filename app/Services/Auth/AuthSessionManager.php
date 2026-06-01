<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\SecurityModel;
use Core\Session;
use Core\Redis;
use App\Services\AuditTrail;
use App\Constants\SessionKeys;
use App\Contracts\LoggerInterface;
use Core\RateLimiter;

class AuthSessionManager
{
    private Session $session;
    private Redis $redis;
    private SessionService $sessionService;
    private AuditTrail $auditTrail;
    private SecurityModel $securityModel;
    private User $userModel;
    private LoggerInterface $logger;
    private RateLimiter $rateLimiter;
    public function __construct(
        Session $session,
        Redis $redis,
        SessionService $sessionService,
        AuditTrail $auditTrail,
        SecurityModel $securityModel,
        User $userModel,
        LoggerInterface $logger,
        RateLimiter $rateLimiter
    ) {        $this->session = $session;
        $this->redis = $redis;
        $this->sessionService = $sessionService;
        $this->auditTrail = $auditTrail;
        $this->securityModel = $securityModel;
        $this->userModel = $userModel;
        $this->logger = $logger;
        $this->rateLimiter = $rateLimiter;
}

    public function createPending2FASession(object $user): void
    {
        $this->session->regenerate(true);
        $this->session->set(SessionKeys::PENDING_2FA_USER_ID, (int)$user->id);
        $this->session->set('pending_2fa_created_at', time());
        $this->session->set('pending_2fa_ip', client_ip());
    }

    public function createSession(object $user, bool $remember = false): void
    {
        $this->session->regenerate(true);
        
        $this->session->set(SessionKeys::USER_ID, (int)$user->id);
        $this->session->set(SessionKeys::LOGGED_IN, true);
        $this->session->set(SessionKeys::USER_ROLE, (string)($user->role ?? 'user'));
        $this->session->set('last_activity', (string)time());
        $this->session->set('login_ip', client_ip());
        $this->session->set('login_time', time());
        $this->session->set('user_verify_time', time());
        $this->session->set('last_auth_time', time());

        if ($remember) {
            $this->createRememberToken((int)$user->id);
        }

        $this->sessionService->recordSession(
            (int)$user->id,
            $this->session->getId(),
            (string)get_user_agent(),
            client_ip(),
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        );
    }

    public function finalizeSessionAfter2FA(object $user): void
    {
        $this->session->regenerate(true);
        
        $this->createSession($user, false);
        $this->session->remove(SessionKeys::PENDING_2FA_USER_ID);
        $this->session->remove('pending_2fa_created_at');
        $this->session->remove('pending_2fa_ip');
        
        $identifier = mb_strtolower($user->email ?? (string)$user->username, 'UTF-8');
        
        $this->rateLimiter->clear('login_id:' . hash('sha256', $identifier));
        $this->rateLimiter->clear('login_ip:' . hash('sha256', client_ip()));
        \Core\Cache::getInstance()->forget('login_attempts:' . hash('sha256', $identifier));
        
        $this->auditTrail->record('auth.login.2fa_completed', (int)$user->id, [
            'ip' => client_ip(),
            'user_agent' => get_user_agent()
        ]);
    }

    private function createRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        
        $this->userModel->update($userId, [
            'remember_token' => $hashedToken,
            'remember_expires_at' => date('Y-m-d H:i:s', strtotime('+30 days'))
        ]);
        
        setcookie('remember_token', $token, [
            'expires' => strtotime('+30 days'),
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }

    public function verifyByRememberToken(string $token): ?object
    {
        $hashedToken = hash('sha256', $token);
        $user = $this->userModel->findByRememberToken($hashedToken);
        
        if (!$user) return null;
        
        if (strtotime((string)$user->remember_expires_at) < time()) {
            return null;
        }

        $this->session->regenerate(true);
        $this->createSession($user, true);
        
        return $user;
    }

    public function logout(): void
    {
        $sessionId = $this->session->getId();
        $userId = $this->session->get(SessionKeys::USER_ID);
        
        if ($userId) {
            $this->logger->activity('auth.logout', '???? ?????', (int)$userId);
            
            $dbSession = $this->securityModel->findSessionBySessionId($sessionId);
            if ($dbSession) {
                $this->sessionService->terminateSession((int)$dbSession->id, (int)$userId);
            }
        }

        try {
            if ($this->redis->isAvailable()) {
                $this->redis->delete("session:activity:{$sessionId}");
            }
        } catch (\Throwable $e) {
            $this->logger->error('auth.logout.redis_clear_failed', ['error' => $e->getMessage()]);
        }

        $this->clearRememberCookie();
        $this->session->destroy();
    }

    public function logoutAll(int $userId): void
    {
        $this->logger->activity('auth.logout_all', '???? ?? ????? ?????????', $userId);
        
        $sessions = $this->sessionService->getActiveSessions($userId);
        
        foreach ($sessions as $session) {
            try {
                if ($this->redis->isAvailable()) {
                    $this->redis->delete("session:activity:" . ($session->session_id ?? ''));
                }
            } catch (\Throwable $e) {
                $this->logger->error('auth.logout_all.redis_clear_failed', [
                    'user_id' => $userId,
                    'session_id' => $session->session_id ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->securityModel->deactivateUserSessions($userId);
        $this->userModel->update($userId, ['remember_token' => null, 'remember_expires_at' => null]);
        
        $this->clearRememberCookie();
        $this->session->destroy();
    }

    public function clearRememberCookie(): void
    {
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'domain' => '',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
    }


    public function check(): bool
    {
        return $this->session->get(SessionKeys::LOGGED_IN) === true;
    }

    public function user(): ?object
    {
        if (!$this->check()) return null;
        return $this->userModel->find((int)$this->session->get(SessionKeys::USER_ID));
    }

    public function getPending2FAUserId(): ?int
    {
        return $this->session->get(SessionKeys::PENDING_2FA_USER_ID) ? (int)$this->session->get(SessionKeys::PENDING_2FA_USER_ID) : null;
    }

    public function getPending2FACreatedAt(): int
    {
        $createdAt = (int)$this->session->get('pending_2fa_created_at', 0);
        if ($createdAt === 0 && $this->session->get('admin_pending_2fa')) {
            $createdAt = (int)$this->session->get('admin_pending_2fa_created', 0);
        }
        return $createdAt;
    }

    public function getPending2FAIp(): ?string
    {
        $pendingIp = $this->session->get('pending_2fa_ip');
        if (empty($pendingIp) && $this->session->get('admin_pending_2fa')) {
            $pendingIp = $this->session->get('admin_pending_2fa_ip');
        }
        return $pendingIp;
    }

    public function destroySession(): void
    {
        $this->session->destroy();
    }
}