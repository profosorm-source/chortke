<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\LogNotificationAdapter;
use App\Contracts\LoggerInterface;

class LogNotificationService extends \App\Services\BaseService
{
    public function __construct(
        private LogNotificationAdapter $adapter,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
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


