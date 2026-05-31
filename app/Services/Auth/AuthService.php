<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\User\UserService;
use App\Services\EmailService;
use App\Validators\RegisterRequest;
use Core\RateLimiter;
use Core\EventDispatcher;
use Core\Database;
use App\Contracts\LoggerInterface;
use App\Events\UserLoggedInEvent;
use App\Events\UserRegisteredEvent;

/**
 * AuthService
 *
 * ???????????? ???? ????? ????.
 */
class AuthService
{
    public function __construct(
        private EventDispatcher $eventDispatcher,
        private Database $db,
        private LoggerInterface $logger,
        private UserService $userService,
        private User $userModel,
        private RateLimiter $rateLimiter,
        private AuthSessionManager $sessionManager,
        private PasswordRecoveryService $passwordService,
        private ?EmailService $emailService = null
    ) {}

    public function checkRateLimit(string $action, string $key): bool
    {
        $ip = client_ip();
        $rateLimitKey = "{$action}:{$key}:{$ip}";
        
        $rateLimitCheck = $this->rateLimiter->attempt($rateLimitKey, 5, 60, true); 
        
        usleep(random_int(50, 150) * 1000);

        return $rateLimitCheck === true;
    }

    public function login(string $identifier, string $password, bool $remember = false): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Auth\ProcessLoginJob::class);
        return $job->handle($identifier, $password, $remember);
    }


    public function loginAsAdmin(string $email, string $password, bool $remember = false): array
    {
        return $this->performLogin($email, $password, $remember, true);
    }

    private function performLogin(string $identifier, string $password, bool $remember, bool $requireAdmin): array
    {
        $ip = client_ip();
        $identifier = trim($identifier);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $identifier = mb_strtolower($identifier, 'UTF-8');
        }
        
        $ipKey = 'login_ip:' . hash('sha256', $ip);
        $idKey = 'login_id:' . hash('sha256', $identifier);
        
        if (!$this->rateLimiter->attempt($ipKey, 10, 1, true) || 
            !$this->rateLimiter->attempt($idKey, 5, 15, true)) {
            
            usleep(random_int(100000, 200000));
            return ['success' => false, 'message' => '????? ???????? ??? ??? ?? ?? ???? ???. ????? ????? ???? ????.'];
        }

        $user = $this->userModel->findByCredentials($identifier);
        
        if ($requireAdmin && $user && !in_array($user->role, ['admin', 'super_admin', 'support'], true)) {
            $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
            return ['success' => false, 'message' => '??????? ???? ??????? ??? ?? ?????? ??? ????? ??? ???.'];
        }

        $user = null;
        $this->db->beginTransaction();
        try {
            $user = $this->userModel->findByCredentialsForUpdate($identifier);

            if ($user) {
                if ($user->status === 'locked' || $user->status === 'locked_2fa') {
                    $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
                    $this->db->rollBack();
                    return ['success' => false, 'message' => '??? ?????? ?? ??? ???? ?????? ???.'];
                }

                if ($user->status === 'banned' || $user->status === 'suspended') {
                    $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
                    $this->db->rollBack();
                    return ['success' => false, 'message' => '???? ?????? ??? ????? ?? ????? ??? ???.'];
                }

                if (empty($user->email_verified_at)) {
                    $this->passwordService->verifyPassword($password, $this->passwordService->getDummyHash());
                    $this->db->rollBack();
                    return ['success' => false, 'message' => '??? ?????? ?? ??? ???? ?????? ???.', 'email_unverified' => true, 'email' => $user->email];
                }
            }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $passwordToVerify = $user ? $user->password : $this->passwordService->getDummyHash();

        usleep(random_int(100000, 300000));

        if (!$this->passwordService->verifyPassword($password, $passwordToVerify, $user ? (int)$user->id : null)) {
            $this->logger->warning('auth.login.failed', ['identifier' => $identifier, 'ip' => $ip]);
            
            if ($user) {
                $attemptsKey = 'login_attempts:' . hash('sha256', $identifier);
                $attempts = \Core\Cache::getInstance()->increment($attemptsKey, 1, 900);
                
                if ($attempts !== false && $attempts >= 10) {
                    if ($this->userModel->lockIfExceededAttempts((int)$user->id)) {
                        $this->logger->critical('auth.account_locked', ['user_id' => $user->id, 'identifier' => $identifier]);
                        if ($this->emailService) {
                            $this->emailService->sendAccountLockedAlert((int)$user->id, $ip);
                        }
                    }
                }
            }

            if ($this->db->inTransaction()) {
                $this->db->commit();
            }
            
            return ['success' => false, 'message' => '??? ?????? ?? ??? ???? ?????? ???.'];
        }

        if ($this->db->inTransaction()) {
            $this->db->commit();
        }

        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            $this->rateLimiter->clear($idKey);
            $this->rateLimiter->clear($ipKey);
            \Core\Cache::getInstance()->forget('login_attempts:' . hash('sha256', $identifier));
            $this->sessionManager->createSession($user, $remember);
        } else {
            $this->sessionManager->createPending2FASession($user);
        }

        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, client_ip(), get_user_agent())
        );
        
        return [
            'success'      => true,
            'message'      => '???? ??????????? ???.',
            'user'         => $user,
            'requires_2fa' => $requires2FA,
        ];
    }

    public function loginDirectly(object $user): array
    {
        $ip = client_ip();
        if (!$this->rateLimiter->attempt('login_direct:' . hash('sha256', $ip), 20, 1, true)) {
            $this->logger->warning('auth.login_directly.throttled', ['user_id' => $user->id, 'ip' => $ip]);
            return ['success' => false, 'message' => '????? ???????? ??? ??? ?? ?? ???? ???.'];
        }

        if ($user->status === 'locked') {
            return ['success' => false, 'message' => '???? ?????? ??? ??? ??? ???.', 'code' => 'ACCOUNT_LOCKED'];
        }

        if (in_array($user->status, ['banned', 'suspended', 'pending'], true)) {
            return ['success' => false, 'message' => '???? ?????? ??? ?????? ????? ?? ?? ?????? ????? ???.', 'code' => 'ACCOUNT_DISABLED'];
        }

        if (empty($user->email_verified_at)) {
            return ['success' => false, 'message' => '????? ????? ????? ???? ???.'];
        }

        $requires2FA = (bool)($user->two_factor_enabled ?? false);
        if (!$requires2FA) {
            $this->sessionManager->createSession($user, false);
        } else {
            $this->sessionManager->createPending2FASession($user);
        }

        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, client_ip(), get_user_agent())
        );

        return [
            'success'      => true,
            'user'         => $user,
            'requires_2fa' => $requires2FA,
        ];
    }

    public function verify2FA(string $code): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Auth\Verify2FAJob::class);
        return $job->handle($code);
    }


    public function validateRegister(array $data): array
    {
        $request = new RegisterRequest($data);
        if (!$request->validate()) {
            $errors = [];
            foreach ($request->errors() as $fieldErrors) {
                foreach ((array)$fieldErrors as $msg) {
                    $errors[] = $msg;
                }
            }
            return $errors;
        }

        $errors = [];
        if ($this->userService->emailExists($data['email'] ?? '')) {
            $errors[] = '??? ????? ????? ??? ??? ???.';
        }

        $policyErrors = \App\Validators\PasswordPolicy::validate($data['password'] ?? '', [
            'username'  => $data['username'] ?? '',
            'email'     => $data['email'] ?? '',
            'full_name' => $data['full_name'] ?? '',
        ]);
        if (!empty($policyErrors)) {
            $errors = array_merge($errors, $policyErrors);
        }

        return $errors;
    }

    public function register(array $data): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Auth\ProcessRegistrationJob::class);
        return $job->handle($data);
    }


    public function requestPasswordReset(string $email): array
    {
        return $this->passwordService->requestPasswordReset($email);
    }

    public function validatePasswordResetToken(string $token): bool
    {
        return $this->passwordService->validatePasswordResetToken($token);
    }

    public function resetPassword(string $token, string $newPassword, ?string $email = null): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Auth\ResetPasswordJob::class);
        return $job->handle($token, $newPassword, $email);
    }


    public function verifyByRememberToken(string $token): ?object
    {
        return $this->sessionManager->verifyByRememberToken($token);
    }

    public function logout(): void
    {
        $this->sessionManager->logout();
    }

    public function logoutAll(int $userId): void
    {
        $this->sessionManager->logoutAll($userId);
    }

    public function check(): bool
    {
        return $this->sessionManager->check();
    }

    public function user(): ?object
    {
        return $this->sessionManager->user();
    }
}
