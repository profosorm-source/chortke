<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

class SendToAllNotificationJob
{
    public function __construct(
        private \App\Models\CryptoDeposit $model
    ) {}

    public function handle(
        string  $title,
        string  $message,
        string  $type       = Notification::TYPE_SYSTEM,
        ?string $actionUrl  = null,
        ?string $actionText = null,
        string  $priority   = Notification::PRIORITY_NORMAL,
        ?array  $data       = null,
        ?string $scheduledAt = null
    ): array {
        $totalSent = 0;
        $totalQueued = 0;

        foreach ($this->model->getActiveUsersIdsInBatches(self::BULK_USER_BATCH) as $batch) {
            $result = $this->sendBulkToUsers($batch, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt);
            $totalSent += $result['sent'] ?? 0;
            $totalQueued += $result['queued'] ? 1 : 0;
        }

        return ['sent' => $totalSent, 'queued_batches' => $totalQueued];
    }
}
