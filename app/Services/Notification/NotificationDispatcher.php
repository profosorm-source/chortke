<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Adapters\Notification\PushNotificationAdapter;
use App\Adapters\Notification\SmsNotificationAdapter;
use App\Adapters\Notification\FcmNotificationAdapter;
use App\Adapters\Notification\LogNotificationAdapter;
use App\Contracts\LoggerInterface;
use Core\EventDispatcher;
use Core\Queue;
use App\Events\NotificationChannelRequestedEvent;
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
        private Queue $queue,
        private NotificationRetryPolicy $retryPolicy,
        protected ?EventDispatcher $eventDispatcher
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
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal'
    ): bool {
        $event = new NotificationChannelRequestedEvent(
            $channel,
            $userId,
            $title,
            $message,
            $data ?? [],
            $imageUrl,
            $actionUrl,
            $actionText,
            $priority
        );

        $listeners = $this->eventDispatcher->getListeners('notification.channel.requested');
        if (empty($listeners)) {
            $this->logger->warning('notif.channel.no_listeners', ['channel' => $channel]);
            return false;
        }

        $this->eventDispatcher->dispatch('notification.channel.requested', $event);
        return true;
    }

    public function handleChannelRequest(NotificationChannelRequestedEvent $event): bool
    {
        return $this->sendToChannel(
            $event->channel,
            $event->userId,
            $event->title,
            $event->message,
            $event->data,
            $event->imageUrl,
            $event->actionUrl,
            $event->actionText
        );
    }

    private function sendToChannel(
        string $channel,
        int $userId,
        string $title,
        string $message,
        ?array $data = null,
        ?string $imageUrl = null,
        ?string $actionUrl = null,
        ?string $actionText = null
    ): bool {
        $channelName = strtolower(trim($channel));

        if (!isset($this->channelHandlers[$channelName])) {
            $this->logger->warning('notif.unknown_channel', ['channel' => $channel]);
            return false;
        }

        try {
            // Execute standard strategy routine with channel-specific retry/circuit policy
            $handler = $this->channelHandlers[$channelName];
            return $this->retryPolicy->execute($channelName, function () use (
                $handler,
                $userId,
                $title,
                $message,
                $data,
                $imageUrl,
                $actionUrl
            ) {
                return (bool) $handler($userId, $title, $message, $data, $imageUrl, $actionUrl);
            });
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
        $batchSize = (int)config('notification.bulk_batch_size', 500);
        $chunks = array_chunk($userIds, $batchSize);
        $pushed = 0;

        foreach ($chunks as $chunk) {
            $msgId = $data['notif_id'] ?? uniqid('msg_', true);
            $payload = [
                'channel' => $channel,
                'user_ids' => $chunk,
                'title' => $title,
                'message' => $message,
                'data' => array_merge($data ?? [], [
                    'idempotency_key' => $msgId
                ]),
                'image_url' => $imageUrl,
                'action_url' => $actionUrl,
                'message_id' => $msgId
            ];

            if ($this->queue->pushUnique(
                SendBulkNotificationJob::class,
                $payload,
                'bulk_notif:' . $channel . ':' . $msgId . ':' . md5(implode(',', $chunk)),
                null,
                0,
                86400
            )) {
                $pushed++;
            }
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


