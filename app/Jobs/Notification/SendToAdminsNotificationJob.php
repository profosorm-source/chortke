<?php

namespace App\Jobs\Notification;

use App\Contracts\JobInterface;

class SendToAdminsNotificationJob implements JobInterface
{
    public function handle(string $type, string $title, string $message, ?array $data = null, string $priority = 'normal'): int
    {

        $type = trim($type);
        $type = preg_replace('/[^A-Za-z0-9_]/', '', $type);
        if ($type === '') {
            $type = 'system';
        }

        $title = mb_substr(trim(strip_tags($title)), 0, 255);
        $message = mb_substr(trim(strip_tags($message)), 0, 1200);

        $adminIds = $this->model->getAdminUsersIds();
        if (empty($adminIds)) {
            return 0;
        }

        // 1. Prefetch preferences for all admins in a single database request
        $this->policyService->prefetchPreferences($adminIds);

        $actionUrl = $data['action_url'] ?? null;
        $actionText = $data['action_text'] ?? null;

        // Sanitize values
        $title = $this->sanitizeNotificationText($title);
        $message = $this->sanitizeNotificationText($message);
        $actionUrl = $this->sanitizeUrl($actionUrl);
        $actionText = $this->sanitizeNotificationText($actionText ?? '');
        $groupKey = $this->sanitizeNotificationText($type);

        $inAppRecords = [];
        $pushAdminIds = [];
        $allowedAdminIds = [];

        foreach ($adminIds as $adminId) {
            $adminId = (int)$adminId;

            // Rate Limit Check
            if (!$this->checkRateLimit($adminId)) {
                $this->logger->info('notif.admin_rate_limited', ['user_id' => $adminId, 'type' => $type]);
                continue;
            }

            $allowedAdminIds[] = $adminId;

            // In-app check
            if ($this->policyService->canSendInApp($adminId, $type)) {
                $scheduledAt = $this->policyService->resolveScheduledTime($adminId, $priority, null);
                
                $inAppRecords[] = [
                    'user_id' => $adminId,
                    'scheduled_at' => $scheduledAt,
                ];
            }

            // Push check
            if ($this->policyService->canSendPush($adminId, $type)) {
                $pushAdminIds[] = $adminId;
            }
        }

        $insertedCount = 0;

        // 2. Perform BULK INSERT for all allowed in-app notifications
        if (!empty($inAppRecords)) {
            try {
                $now = date('Y-m-d H:i:s');
                $placeholders = [];
                $params = [];
                $dataJson = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;

                foreach ($inAppRecords as $record) {
                    $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?)';
                    $params[] = $record['user_id'];
                    $params[] = $type;
                    $params[] = $title;
                    $params[] = $message;
                    $params[] = $dataJson;
                    $params[] = $actionUrl;
                    $params[] = $actionText;
                    $params[] = $priority;
                    $params[] = null; // expires_at
                    $params[] = $now; // created_at
                    $params[] = $record['scheduled_at'];
                    $params[] = Notification::CHANNEL_IN_APP;
                    $params[] = $groupKey;
                }

                $sql = "INSERT INTO notifications 
                        (user_id, type, title, message, data, action_url, action_text, priority, is_read, is_archived, expires_at, created_at, scheduled_at, channel, group_key)
                        VALUES " . implode(', ', $placeholders);

                $this->model->getDb()->query($sql, $params);
                $insertedCount = count($inAppRecords);
            } catch (\Throwable $e) {
                $this->logger->error('notif.admins_bulk_insert_failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        // 3. Perform BULK CACHE INVALIDATION
        if (!empty($allowedAdminIds)) {
            try {
                $this->tracker->invalidateUnreadCacheBulk($allowedAdminIds);
            } catch (\Throwable $e) {
                $this->logger->error('notif.admins_bulk_cache_invalidation_failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        // 4. Perform BULK FCM PUSH DISPATCH
        if (!empty($pushAdminIds)) {
            try {
                $this->queue->pushUnique(
                    \App\Jobs\ProcessNotificationJob::class,
                    [
                        'channel' => 'fcm',
                        'user_ids' => $pushAdminIds,
                        'title' => $title,
                        'message' => $message,
                        'data' => $data,
                        'action_url' => $actionUrl,
                    ],
                    'notif:admins_fcm:' . md5($title . implode(',', $pushAdminIds)),
                    null, 0, 86400
                );
            } catch (\Throwable $e) {
                $this->logger->error('notif.admins_bulk_push_failed', ['error' => $e->getMessage()]);
                throw $e;
            }
        }

        return $insertedCount;
    
    }
}
