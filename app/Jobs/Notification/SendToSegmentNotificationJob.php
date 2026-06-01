<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

class SendToSegmentNotificationJob
{
    private \App\Models\CryptoDeposit $model;
    public function __construct(
        \App\Models\CryptoDeposit $model
    ) {        $this->model = $model;
}

    public function handle(
        string  $segment,
        string  $title,
        string  $message,
        string  $type        = Notification::TYPE_SYSTEM,
        ?string $actionUrl   = null,
        ?string $actionText  = null,
        string  $priority    = Notification::PRIORITY_NORMAL,
        ?array  $data        = null,
        ?string $scheduledAt = null,
        array   $filters     = []
    ): array {
        $totalSent = 0;
        $totalQueued = 0;

        // 🚀 BUG-04 Fix: Use chunked processing to avoid memory OOM
        $this->model->chunkUsersBySegment($segment, 500, function(array $userIds) use (
            $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt, &$totalSent, &$totalQueued
        ) {
            $result = $this->sendBulkToUsers($userIds, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt);
            $totalSent += $result['sent'] ?? 0;
            if ($result['queued'] ?? false) {
                $totalQueued++;
            }
        }, $filters);

        return ['sent' => $totalSent, 'queued_batches' => $totalQueued, 'segment' => $segment];
    }
}
