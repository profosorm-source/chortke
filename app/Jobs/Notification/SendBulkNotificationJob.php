<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

class SendBulkNotificationJob
{
    public function __construct(
        
    ) {}

    public function handle(array $userIds, string $type, string $title, string $message, array $data = [], ?string $actionUrl = null): int
    {
        if (empty($userIds)) return 0;
        
        $queue = \Core\Container::getInstance()->make(\Core\Queue::class);
        $chunks = array_chunk($userIds, 100);
        
        foreach ($chunks as $chunk) {
            $payload = [
                'user_ids' => $chunk,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'action_url' => $actionUrl,
                'action_text' => null,
                'priority' => 'normal',
                'scheduled_at' => null,
            ];
            
            // Fan-out to persist bulk job (Database)
            $queue->push(\App\Jobs\PersistBulkInAppNotificationJob::class, $payload);
            
            // Fan-out to process job (FCM/External Channels)
            $queue->push(\App\Jobs\ProcessNotificationJob::class, [
                'channel' => 'fcm',
                'user_ids' => $chunk,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'action_url' => $actionUrl,
            ]);
        }
        
        return count($userIds);
    }
}
