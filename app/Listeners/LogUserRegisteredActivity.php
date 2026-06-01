<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserRegisteredEvent;
use App\Services\EmailService;
use App\Services\User\UserService;
use App\Contracts\LoggerInterface;

class LogUserRegisteredActivity
{
    private LoggerInterface $logger;
    private UserService $userService;
    private ?EmailService $emailService;
    public function __construct(
        LoggerInterface $logger,
        UserService $userService,
        ?EmailService $emailService = null
    ) {        $this->logger = $logger;
        $this->userService = $userService;
        $this->emailService = $emailService;
}

    public function handle(UserRegisteredEvent $event): void
    {
        try {
            // 1. Log structured registration activity
            $this->logger->activity('auth.register', 'ثبت‌نام کاربر', $event->userId);

            // 2. Resolve verified token and dispatch Verification Mail outside the HTTP pipeline
            $user = $this->userService->find($event->userId);
            if ($this->emailService && $user) {
                // CRITICAL-02 Fix: Use the plain token from the event (not hashed in DB)
                $token = $event->plainToken ?? $user->email_verification_token;
                if (!empty($token)) {
                    $this->emailService->sendVerificationEmail($event->userId, $token);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('listener.user_registered.failed', [
                'user_id' => $event->userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
