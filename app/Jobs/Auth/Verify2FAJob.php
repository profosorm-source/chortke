<?php

declare(strict_types=1);

namespace App\Jobs\Auth;

class Verify2FAJob
{
    private \App\Contracts\LoggerInterface $logger;
    private \App\Models\User $userModel;
    private \App\Services\Auth\TwoFactorService $twoFactorService;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \App\Models\User $userModel,
        \App\Services\Auth\TwoFactorService $twoFactorService
    ) {        $this->logger = $logger;
        $this->userModel = $userModel;
        $this->twoFactorService = $twoFactorService;
}

    public function handle(string $code): array
    {
        $pendingUserId = $this->sessionManager->getPending2FAUserId();
        if (!$pendingUserId) {
            return ['success' => false, 'message' => '??? ??????? 2FA pending ???? ?????.'];
        }

        $createdAt = $this->sessionManager->getPending2FACreatedAt();
        if (time() - $createdAt > 600) { 
            $this->sessionManager->destroySession();
            return ['success' => false, 'message' => '???? 2FA ????? ??? ???. ????? ?????? ???? ????.'];
        }
        
        $pendingIp = $this->sessionManager->getPending2FAIp();
        $currentIp = client_ip();
        $normalize = function(string $ip): string {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $packed = inet_pton($ip);
                if ($packed === false) return $ip;
                return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8));
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $pos = strrpos($ip, '.');
                return $pos !== false ? substr($ip, 0, $pos) : $ip;
            }
            return $ip;
        };

        $pendingSubnet = $normalize($pendingIp ?? '');
        $currentSubnet = $normalize($currentIp);
        if ($pendingSubnet !== $currentSubnet) {
            $this->logger->warning('auth.2fa.ip_mismatch', [
                'pending_ip' => $pendingIp,
                'current_ip' => $currentIp,
                'user_id' => $pendingUserId
            ]);
        }

        $user = $this->userModel->find((int)$pendingUserId);
        if (!$user || !$user->two_factor_enabled) {
            $this->sessionManager->destroySession();
            return ['success' => false, 'message' => '????? ???? ??? ?? 2FA ??????? ???.'];
        }

        if ($user->status === 'locked' || $user->status === 'locked_2fa') {
            $this->sessionManager->destroySession();
            return ['success' => false, 'message' => '???? ??? ?? ???? ???????? ????? ??? ??.'];
        }

        if (!$this->twoFactorService->verifyCode($user->two_factor_secret, $code, (int)$user->id)) {
            $this->logger->activity('auth.2fa_failed', '?? 2FA ???????', (int)$user->id);
            
            $freshUser = $this->userModel->find((int)$user->id);
            if ($freshUser && $freshUser->status === 'locked_2fa') {
                $this->sessionManager->destroySession();
                return ['success' => false, 'message' => '???? ??? ?? ???? ???????? ????? ??? ??.'];
            }
            
            return ['success' => false, 'message' => '?? 2FA ??????? ???.'];
        }

        $this->sessionManager->finalizeSessionAfter2FA($user);

        $this->eventDispatcher->dispatchAsync(
            'auth.login', 
            new UserLoggedInEvent((int)$user->id, client_ip(), get_user_agent())
        );

        return [
            'success' => true,
            'message' => '????? ???? ?? ???????? ????? ??.',
            'user' => $user,
        ];
    }
}
