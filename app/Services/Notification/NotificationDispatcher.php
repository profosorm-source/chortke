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

/**
 * NotificationDispatcher — Channel Router برای ارسال نوتیفیکیشن‌ها
 */
class NotificationDispatcher
{
    /**
     * @var array<string, \App\Contracts\NotificationChannelInterface>
     */
    private array $channels = [];

    private \App\Contracts\LoggerInterface $logger;
    private \App\Services\Notification\Channels\PushChannel $pushChannel;
    private \App\Services\Notification\Channels\SmsChannel $smsChannel;
    private \App\Services\Notification\Channels\FcmChannel $fcmChannel;
    private \App\Services\Notification\Channels\LogChannel $logChannel;
    private Queue $queue;
    private NotificationRetryPolicy $retryPolicy;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Notification\Channels\PushChannel $pushChannel,
        \App\Services\Notification\Channels\SmsChannel $smsChannel,
        \App\Services\Notification\Channels\FcmChannel $fcmChannel,
        \App\Services\Notification\Channels\LogChannel $logChannel,
        Queue $queue,
        NotificationRetryPolicy $retryPolicy
    ) {        $this->logger = $logger;
        $this->pushChannel = $pushChannel;
        $this->smsChannel = $smsChannel;
        $this->fcmChannel = $fcmChannel;
        $this->logChannel = $logChannel;
        $this->queue = $queue;
        $this->retryPolicy = $retryPolicy;

                $this->registerChannel($this->pushChannel);
        $this->registerChannel($this->smsChannel);
        $this->registerChannel($this->fcmChannel);
        $this->registerChannel($this->logChannel);
    }

    /**
     * Allows registering new, custom adapters dynamically without touching the dispatcher core code.
     */
    public function registerChannel(\App\Contracts\NotificationChannelInterface $channel): void
    {
        $this->channels[strtolower(trim($channel->getName()))] = $channel;
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
        return $this->sendToChannel(
            $channel,
            $userId,
            $title,
            $message,
            $data,
            $imageUrl,
            $actionUrl,
            $actionText
        );
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

        if (!isset($this->channels[$channelName])) {
            $this->logger->warning('notif.unknown_channel', ['channel' => $channel]);
            return false;
        }

        try {
            // Execute standard strategy routine with channel-specific retry/circuit policy
            $handler = $this->channels[$channelName];
            return $this->retryPolicy->execute($channelName, function () use (
                $handler,
                $userId,
                $title,
                $message,
                $data,
                $imageUrl,
                $actionUrl
            ) {
                return $handler->send($userId, $title, $message, $data, $imageUrl, $actionUrl);
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
        if (empty($userIds)) {
            return ['success' => true, 'processed' => 0];
        }

        // 🚀 Prefetch preferences to avoid N+1 database queries
        try {
            $prefService = \Core\Container::getInstance()->make(
                NotificationPreferenceService::class
            );
            $prefService->prefetchPreferences($userIds);
        } catch (\Throwable $e) {
            // Safe fallback if service is not resolvable
        }

        $cache = \Core\Cache::getInstance();
        $msgId = $data['notif_id'] ?? ($data['message_id'] ?? null);
        $processed = 0;

        foreach ($userIds as $userId) {
            $dedupKey = null;
            if ($msgId) {
                $dedupKey = "notif_sent:{$channel}:{$msgId}:{$userId}";
                if ($cache->get($dedupKey)) {
                    continue; // Skip already dispatched notification
                }
            }

            $success = $this->sendToChannel(
                $channel,
                (int)$userId,
                $title,
                $message,
                $data,
                $imageUrl,
                $actionUrl
            );

            if ($success) {
                $processed++;
                if ($dedupKey) {
                    $cache->putSeconds($dedupKey, '1', 86400);
                }
            }
        }

        $this->logger->info('notif.bulk_dispatched_directly', [
            'channel' => $channel,
            'total_users' => count($userIds),
            'processed' => $processed
        ]);

        return ['success' => true, 'processed' => $processed];
    }
}


