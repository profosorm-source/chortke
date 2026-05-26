<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Contracts\LoggerInterface;
use Core\Container;

class NotificationRequestListener
{
    private Container $container;
    private LoggerInterface $logger;

    public function __construct(Container $container, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->logger = $logger;
    }

    public function handle($event)
    {
        try {
            if ($event instanceof \App\Events\NotificationRequestedEvent) {
                $userId = $event->userId;
                $type = $event->type;
                $title = $event->title;
                $message = $event->message;
                $data = $event->data ?? [];
                $actionUrl = $event->actionUrl ?? null;
                $actionText = $event->actionText ?? null;
                $priority = $event->priority ?? 'normal';
            } elseif ($event instanceof \Core\Event) {
                $d = $event->getData();
                $userId = $d['user_id'] ?? ($d['recipient_id'] ?? null);
                $type = $d['type'] ?? 'system';
                $title = $d['title'] ?? '';
                $message = $d['message'] ?? '';
                $data = $d['data'] ?? [];
                $actionUrl = $d['action_url'] ?? null;
                $actionText = $d['action_text'] ?? null;
                $priority = $d['priority'] ?? 'normal';
            } elseif (is_array($event)) {
                $userId = $event['user_id'] ?? ($event['recipient_id'] ?? null);
                $type = $event['type'] ?? 'system';
                $title = $event['title'] ?? '';
                $message = $event['message'] ?? '';
                $data = $event['data'] ?? [];
                $actionUrl = $event['action_url'] ?? null;
                $actionText = $event['action_text'] ?? null;
                $priority = $event['priority'] ?? 'normal';
            } else {
                return;
            }

            if (empty($userId)) {
                $this->logger->warning('notification.request.missing_user', ['event' => $event]);
                return;
            }

            $notificationService = $this->container->make(\App\Services\Notification\NotificationService::class);
            $notificationService->send((int)$userId, (string)$type, (string)$title, (string)$message, (array)$data, $actionUrl, $actionText, $priority);

        } catch (\Throwable $e) {
            $this->logger->error('notification.request.listener_failed', ['error' => $e->getMessage(), 'event' => $event]);
        }
    }
}
