<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\PushNotificationAdapter;
use App\Adapters\Notification\SmsNotificationAdapter;
use App\Adapters\Notification\FcmNotificationAdapter;
use App\Adapters\Notification\LogNotificationAdapter;
use App\Contracts\LoggerInterface;
use Core\Queue;
use App\Jobs\SendBulkNotificationJob;

/**
 * NotificationDispatcher — Channel Router برای ارسال نوتیفیکیشن‌ها
 */
class NotificationDispatcher extends \App\Services\BaseService
{
    private array $channelHandlers = [];

    public function __construct(
        private PushNotificationAdapter $pushAdapter,
        private SmsNotificationAdapter $smsAdapter,
        private FcmNotificationAdapter $fcmAdapter,
        private LogNotificationAdapter $logAdapter,
        protected LoggerInterface $logger,
        private Queue $queue
    ) {
        parent::__construct($logger);
        $this->initializeDefaultChannels();
    }

    /**
     * HIGH-01: Satisfying the Open/Closed principle by mapping drivers via dynamic Strategical registry.
     */
    private function initializeDefaultChannels(): void
    {
        $this->registerChannel('push', function(int $uid, string $title, string $msg, ?array $data, ?string $img, ?string $url) {
            return $this->pushAdapter->sendToUser($uid, $title, $msg, $data, $img, $url);
        });

        $this->registerChannel('fcm', function(int $uid, string $title, string $msg, ?array $data, ?string $img, ?string $url) {
            return $this->fcmAdapter->sendToUser($uid, $title, $msg, $data, $img, $url);
        });

        $this->registerChannel('sms', function(int $uid, string $title, string $msg, ?array $data, ?string $img, ?string $url) {
            return $this->smsAdapter->sendToUser($uid, $msg);
        });

        $this->registerChannel('log', function(int $uid, string $title, string $msg, ?array $data, ?string $img, ?string $url) {
            return $this->logAdapter->sendAlert($title, $msg);
        });
    }

    /**
     * Allows registering new, custom adapters dynamically without touching the dispatcher core code.
     */
    public function registerChannel(string $channel, callable $handler): void
    {
        $this->channelHandlers[strtolower(trim($channel))] = $handler;
    }

    /**
     * ارسال نوتیفیکیشن به کانال مشخص با اجرای Strategy منطبق
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
        $channelName = strtolower(trim($channel));

        if (!isset($this->channelHandlers[$channelName])) {
            $this->logger->warning('notif.unknown_channel', ['channel' => $channel]);
            return false;
        }

        try {
            // Execute standard strategy routine
            $handler = $this->channelHandlers[$channelName];
            return (bool)$handler($userId, $title, $message, $data, $imageUrl, $actionUrl);
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


