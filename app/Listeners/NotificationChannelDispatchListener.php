<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NotificationChannelRequestedEvent;
use App\Services\Notification\NotificationDispatcher;
use App\Contracts\LoggerInterface;

class NotificationChannelDispatchListener
{
    public function __construct(
        private NotificationDispatcher $dispatcher,
        private LoggerInterface $logger
    ) {}

    public function handle(NotificationChannelRequestedEvent $event): void
    {
        try {
            if (!$this->dispatcher->handleChannelRequest($event)) {
                $this->logger->warning('notification.channel.dispatch_failed', [
                    'channel' => $event->channel,
                    'user_id' => $event->userId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('notification.channel.listener_failed', [
                'channel' => $event->channel,
                'user_id' => $event->userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
