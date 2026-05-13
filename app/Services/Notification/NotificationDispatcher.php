<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Services\Notification\Adapters\PushNotificationAdapter;
use App\Services\Notification\Adapters\SmsNotificationAdapter;
use App\Services\Notification\Adapters\FcmNotificationAdapter;
use App\Services\Notification\Adapters\LogNotificationAdapter;
use App\Contracts\LoggerInterface;
use Core\Queue;
use App\Jobs\SendBulkNotificationJob;

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
        protected LoggerInterface $logger,
        private Queue $queue
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
        $chunks = array_chunk($userIds, 100);
        $pushed = 0;

        foreach ($chunks as $chunk) {
            $this->queue->push(
                SendBulkNotificationJob::class,
                [
                    'channel' => $channel,
                    'user_ids' => $chunk,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'image_url' => $imageUrl,
                    'action_url' => $actionUrl
                ]
            );
            $pushed++;
        }

        $this->logger->info('notif.bulk_queued', [
            'channel' => $channel,
            'total_users' => count($userIds),
            'chunks' => $pushed
        ]);

        // بازگرداندن پاسخ استاندارد
        return ['success' => true, 'queued_chunks' => $pushed];
    }
}
