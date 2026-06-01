<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\SmsNotificationAdapter;
use App\Contracts\LoggerInterface;

class SmsNotificationService
{
    private SmsNotificationAdapter $adapter;
    public function __construct(
        SmsNotificationAdapter $adapter
    ) {        $this->adapter = $adapter;

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


