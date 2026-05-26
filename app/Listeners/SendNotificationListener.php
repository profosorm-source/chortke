<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NotificationRequestedEvent;
use App\Services\Notification\NotificationService;
use App\Contracts\LoggerInterface;

class SendNotificationListener
{
    public function __construct(
        private NotificationService $notificationService,
        private LoggerInterface $logger
    ) {}

    public function handle(NotificationRequestedEvent $event): void
    {
        try {
            $this->notificationService->send(
                $event->userId,
                $event->eventKey,
                $event->title,
                $event->body,
                $event->payload
            );
        } catch (\Throwable $e) {
            $this->logger->error('notification.listener.failed', [
                'user_id' => $event->userId,
                'event_key' => $event->eventKey,
                'error' => $e->getMessage()
            ]);
        }
    }
}
