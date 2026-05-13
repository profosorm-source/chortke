<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Services\Notification\Adapters\SmsNotificationAdapter;
use App\Contracts\LoggerInterface;

class SmsNotificationService extends \App\Services\BaseService
{
    public function __construct(
        private SmsNotificationAdapter $adapter,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function send(string $mobile, string $message): bool
    {
        return $this->adapter->send($mobile, $message);
    }

    public function sendSecurityAlert(string $mobile, string $message): bool
    {
        return $this->adapter->sendSecurityAlert($mobile, $message);
    }

    public function sendSecurityAlertToUser(int $userId, string $message): bool
    {
        return $this->adapter->sendSecurityAlertToUser($userId, $message);
    }

    public function sendWithdrawalAlert(string $mobile, float $amount, string $currency): bool
    {
        return $this->adapter->sendWithdrawalAlert($mobile, $amount, $currency);
    }

    public function sendWithdrawalAlertToUser(int $userId, float $amount, string $currency): bool
    {
        return $this->adapter->sendWithdrawalAlertToUser($userId, $amount, $currency);
    }

    public function isEnabled(): bool
    {
        return $this->adapter->isEnabled();
    }
}
