<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserRegisteredEvent;
use App\Services\EmailService;
use App\Services\User\UserService;
use App\Contracts\LoggerInterface;

class LogUserRegisteredActivity
{
    public function __construct(
        private LoggerInterface $logger,
        private UserService $userService,
        private ?EmailService $emailService = null
    ) {}

    public function handle(UserRegisteredEvent $event): void
    {
        try {
            // 1. Log structured registration activity
            $this->logger->activity('auth.register', 'ثبت‌نام کاربر', $event->userId);

            // 2. Resolve verified token and dispatch Verification Mail outside the HTTP pipeline
            $user = $this->userService->find($event->userId);
            if ($this->emailService && $user && !empty($user->email_verification_token)) {
                $this->emailService->sendVerificationEmail($event->userId, $user->email_verification_token);
            }
        } catch (\Throwable $e) {
            $this->logger->error('listener.user_registered.failed', [
                'user_id' => $event->userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
