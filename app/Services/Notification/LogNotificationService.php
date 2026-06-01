<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\LogNotificationAdapter;
use App\Contracts\LoggerInterface;

class LogNotificationService
{
    private LogNotificationAdapter $adapter;
    public function __construct(
        LogNotificationAdapter $adapter
    ) {        $this->adapter = $adapter;

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


