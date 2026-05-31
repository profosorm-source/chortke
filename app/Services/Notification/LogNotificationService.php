<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\LogNotificationAdapter;
use App\Contracts\LoggerInterface;

class LogNotificationService
{
    public function __construct(
        private LogNotificationAdapter $adapter
    ) {
            }

    public function sendAlert(string $title, string $message, string $severity = 'medium'): void
    {
        $this->adapter->sendAlert($title, $message, $severity);
    }

    public function checkAlertRules(): void
    {
        $this->adapter->checkAlertRules();
    }
}


