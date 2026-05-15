<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\UserLoggedInEvent;
use App\Services\AuditTrail;
use App\Contracts\LoggerInterface;

class LogUserLoggedInActivity
{
    public function __construct(
        private LoggerInterface $logger,
        private AuditTrail $auditTrail
    ) {}

    public function handle(UserLoggedInEvent $event): void
    {
        try {
            // Record PSR-3 dynamic structured logs via buffer (fast)
            $this->logger->activity('auth.login', 'ورود موفق کاربر', $event->userId);

            // Record persistent audit trails to database securely
            $this->auditTrail->record('auth.login', $event->userId, [
                'ip' => $event->ipAddress,
                'user_agent' => $event->userAgent
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('listener.user_logged_in.failed', [
                'user_id' => $event->userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
