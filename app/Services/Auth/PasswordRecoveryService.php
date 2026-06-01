<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Models\SecurityModel;
use App\Services\User\UserService;
use App\Services\EmailService;
use Core\RateLimiter;
use App\Contracts\LoggerInterface;
use Core\EventDispatcher;

class PasswordRecoveryService
{
    private static ?string $cachedDummyHash = null;

    private SecurityModel $securityModel;
    private User $userModel;
    private UserService $userService;
    private RateLimiter $rateLimiter;
    private LoggerInterface $logger;
    private EventDispatcher $eventDispatcher;
    private ?EmailService $emailService;
    public function __construct(
        SecurityModel $securityModel,
        User $userModel,
        UserService $userService,
        RateLimiter $rateLimiter,
        LoggerInterface $logger,
        EventDispatcher $eventDispatcher,
        ?EmailService $emailService = null
    ) {        $this->securityModel = $securityModel;
        $this->userModel = $userModel;
        $this->userService = $userService;
        $this->rateLimiter = $rateLimiter;
        $this->logger = $logger;
        $this->eventDispatcher = $eventDispatcher;
        $this->emailService = $emailService;
}

    public function verifyPassword(string $password, string $hash, ?int $userId = null): bool
    {
        if ($password === '') return false;

        $inputPassword = base64_encode(hash('sha384', $password, true));
        
        if (password_verify($inputPassword, $hash)) {
            return true;
        }

        if (password_verify($password, $hash)) {
            if ($userId) {
                $this->eventDispatcher->dispatchAsync(
                    'auth.rehash_password',
                    ['user_id' => $userId, 'password' => $password]
                );
            }
            return true;
        }

        return false;
    }

    public function requestPasswordReset(string $email): array
    {
        $ip = client_ip();
        $rateLimitKey = "pw_reset:" . hash('sha256', "{$email}:{$ip}");
        
        if (!$this->rateLimiter->attempt($rateLimitKey, 3, 60, true)) {
            $seconds = $this->rateLimiter->availableIn($rateLimitKey);
            $minutes = (int)ceil($seconds / 60);
            
            $this->logger->warning('auth.password_reset.rate_limited', [
                'email' => $email,
                'ip' => $ip
            ]);
            
            return [
                'success' => false, 
                'message' => "????? ??????????? ??????? ??? ?? ?? ???? ???. ????? {$minutes} ????? ???? ?????? ????."
            ];
        }

        $user = $this->userModel->findByEmail($email);
        $genericMsg = '??? ??? ????? ?? ????? ??? ??? ????? ???? ??????? ???? ??? ????? ??????.';

        $token = bin2hex(random_bytes(32));
        $this->securityModel->createPasswordResetToken($email, $token);

        if ($user) {
            if ($this->emailService) {
                $this->emailService->sendPasswordResetEmail((int)$user->id, $token);
            }
            $this->logger->activity('auth.password_reset.requested', '??????? ??????? ??? ????', (int)$user->id);
        } else {
            usleep(random_int(100000, 300000));
        }

        return ['success' => true, 'message' => $genericMsg];
    }

    public function validatePasswordResetToken(string $token): bool
    {
        $timeout = (int)config('auth.password_reset_ttl', 3600);
        $record = $this->securityModel->findPasswordResetByToken($token, $timeout);
        return $record !== null;
    }

    public function resetPassword(string $token, string $newPassword, ?string $email = null): array
    {
        $timeout = (int)config('auth.password_reset_ttl', 3600);
        $record = $this->securityModel->findPasswordResetByToken($token, $timeout);
        
        if (!$record) {
            return ['success' => false, 'message' => '???? ??????? ??????? ?? ????? ??? ???.'];
        }

        if ($email !== null && mb_strtolower($record->email, 'UTF-8') !== mb_strtolower($email, 'UTF-8')) {
            $this->logger->critical('auth.password_reset.email_mismatch', [
                'token' => $token,
                'expected' => $record->email,
                'provided' => $email
            ]);
            return ['success' => false, 'message' => '??????? ??????? ??????? ???.'];
        }

        $user = $this->userModel->findByEmail($record->email);
        if (!$user) {
            $this->securityModel->deletePasswordResetByEmail($record->email);
            return ['success' => false, 'message' => '???? ??????? ??????? ?? ????? ??? ???.'];
        }

        $passwordToHash = base64_encode(hash('sha384', $newPassword, true));
        $this->userService->changePassword((int)$user->id, $passwordToHash);
        $this->securityModel->deletePasswordResetByEmail($record->email);

        $this->logger->activity('auth.password_reset.completed', '??????? ??? ???? ????? ??', (int)$user->id);
        return ['success' => true, 'message' => '??? ???? ?? ?????? ????? ???.'];
    }

    public function getDummyHash(): string
    {
        if (self::$cachedDummyHash === null) {
            self::$cachedDummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        }
        return self::$cachedDummyHash;
    }
}
