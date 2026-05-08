<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Services\Notification\Adapters\PushNotificationAdapter;
use App\Services\Notification\Adapters\SmsNotificationAdapter;
use App\Services\Notification\Adapters\FcmNotificationAdapter;
use App\Services\Notification\Adapters\LogNotificationAdapter;
use App\Contracts\LoggerInterface;

/**
 * NotificationDispatcher — Channel Router برای ارسال نوتیفیکیشن‌ها
 */
class NotificationDispatcher extends \App\Services\BaseService
{
    public function __construct(
        private PushNotificationAdapter $pushAdapter,
        private SmsNotificationAdapter $smsAdapter,
        private FcmNotificationAdapter $fcmAdapter,
        private LogNotificationAdapter $logAdapter,
        protected LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * ارسال نوتیفیکیشن به کانال مشخص
     */
    public function dispatch(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): bool {
        try {
            switch ($channel) {
                case 'push':
                    return $this->pushAdapter->sendToUser($userId, $title, $message, $data, $imageUrl, $actionUrl);
                case 'sms':
                    return $this->smsAdapter->sendToUser($userId, $message);
                case 'fcm':
                    return $this->fcmAdapter->sendToUser($userId, $title, $message, $data, $imageUrl, $actionUrl);
                case 'log':
                    return $this->logAdapter->sendAlert($title, $message);
                default:
                    $this->logger->warning('notif.unknown_channel', ['channel' => $channel]);
                    return false;
            }
        } catch (\Throwable $e) {
            $this->logger->error('notif.dispatch_failed', [
                'channel' => $channel,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * ارسال bulk به کانال مشخص
     */
    public function dispatchBulk(
        string $channel,
        array $userIds,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null
    ): array {
        $sent = 0;
        $failed = 0;
        foreach ($userIds as $userId) {
            if ($this->dispatch($channel, $userId, $title, $message, $data, $imageUrl, $actionUrl)) {
                $sent++;
            } else {
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }
}
