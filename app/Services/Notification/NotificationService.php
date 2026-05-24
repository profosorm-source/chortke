<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\RateLimiter;
use App\Services\EmailService;
use App\Services\SettingService;
use App\Services\Notification\FcmService;

use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;

/**
 * NotificationService — Orchestrator و Facade اصلی سیستم نوتیفیکیشن
 */
class NotificationService extends \App\Services\BaseService implements NotificationServiceInterface
{
    private const RATE_MAX_PER_USER_PER_HOUR = 20;
    private const RATE_WINDOW_MINUTES        = 60;
    private const BULK_USER_BATCH            = 200;

    public function __construct(
        private Notification $model,
        private NotificationPolicyService $policyService,
        private NotificationTemplateService $templateService,
        private NotificationTracker $tracker,
        private \Core\Queue $queue,
        protected LoggerInterface $logger,
        private ?\App\Services\OutboxService $outbox = null
    ) {
        parent::__construct($logger);
    }

    /**
     * ارسال نوتیفیکیشن به یک کاربر (بخش‌بندی شده برای کاهش وابستگی‌های یکپارچه)
     *
     * Section 8.2 — Opt-in idempotency: callers can pass
     *   $data['idempotency_key'] = 'business_intent:identifier'
     * to prevent duplicate notification rows when the same business event
     * fires twice (e.g. webhook replay, queue at-least-once retry).
     * Without that key, send() behaves exactly as before.
     */
    public function send(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data        = null,
        ?string $actionUrl   = null,
        ?string $actionText  = null,
        string  $priority    = Notification::PRIORITY_NORMAL,
        ?string $expiresAt   = null,
        ?string $imageUrl    = null,
        ?string $groupKey    = null,
        ?string $scheduledAt = null
    ): ?int {
        $idemKey = is_array($data) && !empty($data['idempotency_key'])
            ? (string)$data['idempotency_key']
            : null;

        if ($idemKey !== null) {
            $result = $this->idempotent(
                'notification.send',
                $userId,
                ['type' => $type, 'idem' => $idemKey],
                fn() => ['id' => $this->sendInternal(
                    $userId, $type, $title, $message, $data,
                    $actionUrl, $actionText, $priority, $expiresAt,
                    $imageUrl, $groupKey, $scheduledAt
                )]
            );
            $id = $result['id'] ?? null;
            return is_int($id) ? $id : null;
        }

        return $this->sendInternal(
            $userId, $type, $title, $message, $data,
            $actionUrl, $actionText, $priority, $expiresAt,
            $imageUrl, $groupKey, $scheduledAt
        );
    }

    private function sendInternal(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data,
        ?string $actionUrl,
        ?string $actionText,
        string  $priority,
        ?string $expiresAt,
        ?string $imageUrl,
        ?string $groupKey,
        ?string $scheduledAt
    ): ?int {
        // 1. Rate Limiter assertion
        if (!$this->checkRateLimit($userId)) {
            $this->logger->info('notif.rate_limited', ['user_id' => $userId, 'type' => $type]);
            return null;
        }

        // 2. DND Scheduling resolution
        $scheduledAt = $this->resolveScheduledTime($userId, $priority, $scheduledAt);

        // 2.5 Sanitize user-facing notification content
        $title = $this->sanitizeNotificationText($title);
        $message = $this->sanitizeNotificationText($message);
        $actionUrl = $this->sanitizeUrl($actionUrl);
        $actionText = $this->sanitizeNotificationText($actionText ?? '');
        $groupKey = $this->sanitizeNotificationText($groupKey ?? $type);

        // 3. Persist Database record
        $notifId = $this->persistInAppNotification(
            $userId, $type, $title, $message, $data,
            $actionUrl, $actionText, $priority, $expiresAt, $imageUrl, $groupKey, $scheduledAt
        );

        // 4. Dispatch External Push Channels (FCM)
        $this->dispatchPushNotification(
            $userId, $type, $title, $message, $data,
            $actionUrl, $imageUrl, $scheduledAt, $notifId
        );

        return $notifId;
    }

    /**
     * Handles DND adjustments for specific users.
     */
    private function resolveScheduledTime(int $userId, string $priority, ?string $scheduledAt): ?string
    {
        return $this->policyService->resolveScheduledTime($userId, $priority, $scheduledAt);
    }

    /**
     * Handles database archiving and unread counter resets.
     */
    private function persistInAppNotification(
        int $userId, string $type, string $title, string $message, ?array $data,
        ?string $actionUrl, ?string $actionText, string $priority, ?string $expiresAt,
        ?string $imageUrl, ?string $groupKey, ?string $scheduledAt
    ): ?int {
        try {
            if (!$this->policyService->canSendInApp($userId, $type)) {
                return null;
            }

            $notifId = $this->model->create([
                'user_id'      => $userId,
                'type'         => $type,
                'title'        => $title,
                'message'      => $message,
                'data'         => $data,
                'action_url'   => $actionUrl,
                'action_text'  => $actionText,
                'priority'     => $priority,
                'expires_at'   => $expiresAt,
                'image_url'    => $imageUrl,
                'group_key'    => $groupKey ?? $type,
                'channel'      => Notification::CHANNEL_IN_APP,
                'scheduled_at' => $scheduledAt,
            ]) ?: null;

            if ($notifId && $scheduledAt === null) {
                $this->tracker->invalidateUnreadCache($userId);
            }

            return $notifId;
        } catch (\Throwable $e) {
            $this->logger->error('notif.in_app_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Dispatches remote notifications.
     */
    private function dispatchPushNotification(
        int $userId, string $type, string $title, string $message, ?array $data,
        ?string $actionUrl, ?string $imageUrl, ?string $scheduledAt, ?int $notifId
    ): void {
        if ($scheduledAt !== null || !$this->policyService->canSendPush($userId, $type)) {
            return;
        }

        $idempotencyKey = $notifId ? 'push_' . $notifId : uniqid('push_', true);
        $messageId = $notifId ? (string)$notifId : uniqid('msg_', true);

        try {
            // 🚀 UPG: استفاده از صف سیستم جهت پردازش کاملاً ناهمگام و جلوگیری از مسدودسازی پاسخ HTTP
            $payload = [
                'channel' => 'fcm',
                'user_ids' => [$userId],
                'title' => $title,
                'message' => $message,
                'data' => array_merge($data ?? [], [
                    'type' => $type,
                    'notif_id' => (string)($notifId ?? ''),
                    'idempotency_key' => $idempotencyKey
                ]),
                'image_url' => $imageUrl,
                'action_url' => $actionUrl,
                'message_id' => $messageId,
            ];

            $this->queue->pushUnique(
                \App\Jobs\ProcessNotificationJob::class,
                $payload,
                'notif:fcm:' . $messageId . ':' . $userId,
                null,
                0,
                86400
            );
        } catch (\Throwable $e) {
            $this->logger->warning('notif.push_queue_failed_falling_back_to_outbox', ['user_id' => $userId, 'error' => $e->getMessage()]);

            // 🚀 Completely Async: Avoid synchronous fallback block which causes execution delay.
            // Persist payload to Outbox instead of blocking with direct dispatcher HTTP request.
            $payload = [
                'notification' => [
                    'method' => 'dispatch',
                    'args' => [
                        'fcm',
                        $userId,
                        $title,
                        $message,
                        array_merge($data ?? [], [
                            'type' => $type,
                            'notif_id' => (string)($notifId ?? ''),
                            'idempotency_key' => $idempotencyKey
                        ]),
                        $imageUrl,
                        $actionUrl,
                    ],
                ],
                'metadata' => [
                    'user_id' => $userId,
                    'message_id' => $messageId,
                    'type' => $type,
                    'fallback' => 'notification_push',
                    'queue_error' => $e->getMessage(),
                ],
            ];

            if ($this->outbox) {
                try {
                    $this->outbox->record(
                        'notification',
                        (string)$userId,
                        'push_failed',
                        $payload,
                        null
                    );
                } catch (\Throwable $outboxWriteError) {
                    $this->logger->error('notif.outbox_save_failed', [
                        'user_id' => $userId,
                        'error' => $outboxWriteError->getMessage(),
                    ]);
                }
            } else {
                $this->logger->critical('notif.outbox_and_queue_both_failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function checkRateLimit(int $userId): bool
    {
        return $this->policyService->checkRateLimit($userId);
    }

    public function sendFromTemplate(
        int    $userId,
        string $templateKey,
        array  $vars       = [],
        string $priority   = Notification::PRIORITY_NORMAL,
        ?string $actionUrl = null,
        ?string $actionText= null,
        ?string $groupKey  = null,
        ?string $scheduledAt = null
    ): ?int {
        $rendered = $this->templateService->renderTemplate($templateKey, $vars);
        $prefix = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', explode('_', $templateKey)[0] ?? ''));
        $allowedTypes = [
            'deposit' => Notification::TYPE_DEPOSIT,
            'withdrawal' => Notification::TYPE_WITHDRAWAL,
            'task' => Notification::TYPE_TASK,
            'kyc' => Notification::TYPE_KYC,
            'lottery' => Notification::TYPE_LOTTERY,
            'referral' => Notification::TYPE_REFERRAL,
            'security' => Notification::TYPE_SECURITY,
            'investment' => Notification::TYPE_INVESTMENT,
            'info' => Notification::TYPE_INFO,
            'marketing' => Notification::TYPE_MARKETING,
        ];

        $type = $allowedTypes[$prefix] ?? Notification::TYPE_SYSTEM;

        return $this->send(
            $userId,
            $type,
            $rendered['title'],
            $rendered['message'],
            $vars,
            $actionUrl,
            $actionText,
            $priority,
            null,
            null,
            $groupKey ?? $templateKey,
            $scheduledAt
        );
    }

    public function sendToAll(
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

    public function sendToSegment(
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

    public function sendBulk(
        array   $userIds,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data      = null,
        ?string $actionUrl = null,
        string  $priority  = Notification::PRIORITY_NORMAL
    ): int {
        $sent = 0;
        foreach ($userIds as $userId) {
            if ($this->send((int)$userId, $type, $title, $message, $data, $actionUrl, null, $priority)) {
                $sent++;
            }
        }
        return $sent;
    }

    private function sendBulkToUsers(
        array $userIds, string $type, string $title, string $message,
        ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt
    ): array {
        if (empty($userIds)) {
            return ['sent' => 0, 'skipped' => 0];
        }

        $this->policyService->prefetchPreferences($userIds);

        // HIGH-02: 1. Push Dispatch Offloading to System Queue (Fully Async)
        try {
            $this->queue->pushUnique(
                \App\Jobs\ProcessNotificationJob::class,
                [
                    'channel' => 'fcm',
                    'user_ids' => $userIds,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'action_url' => $actionUrl,
                ],
                'notif:bulk:fcm:' . md5(json_encode($userIds) . $title),
                null, 0, 86400
            );
        } catch (\Throwable $e) {
            $this->logger->error('notif.bulk_async_offload_failed', ['error' => $e->getMessage()]);
        }

        // 🚀 UPG-03: 2. Local Database Recording (Offloaded completely to background queues to avoid HTTP timeout)
        $chunks = array_chunk($userIds, 100);
        $pushedChunks = 0;

        foreach ($chunks as $chunk) {
            try {
                $payload = [
                    'user_ids' => $chunk,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'data' => $data,
                    'action_url' => $actionUrl,
                    'action_text' => $actionText,
                    'priority' => $priority,
                    'scheduled_at' => $scheduledAt,
                ];

                if ($this->queue->pushUnique(
                    \App\Jobs\PersistBulkInAppNotificationJob::class,
                    $payload,
                    'persist_bulk_notif:' . md5(json_encode($payload, JSON_UNESCAPED_UNICODE)),
                    null,
                    0,
                    86400
                )) {
                    $pushedChunks++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('notif.bulk_db_queue_failed', [
                    'error' => $e->getMessage(),
                    'chunk_size' => count($chunk)
                ]);
            }
            unset($chunk); // Help GC cycle release memory segments
        }

        $this->logger->info('notif.bulk_db_writing_queued', [
            'total_users' => count($userIds),
            'queued_chunks' => $pushedChunks
        ]);

        return ['sent' => count($userIds), 'skipped' => 0, 'queued' => true];
    }

    private function sanitizeNotificationText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
        return mb_substr(trim($text), 0, 1200);
    }

    private function sanitizeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $normalized = trim($url);
        if ($normalized === '') {
            return null;
        }

        $parsed = parse_url($normalized);

        // 🛡️ NEW-04: فقط آدرس‌های نسبی (relative URLs) مجاز هستند تا از حملات Open Redirect یا XSS جلوگیری شود
        if (isset($parsed['scheme']) || isset($parsed['host'])) {
            $this->logger->warning('notification.invalid_url', ['url' => $normalized]);
            return null;
        }

        return filter_var($normalized, FILTER_SANITIZE_URL);
    }

    /**
     * 🚀 UPG-03: متد کمکی برای ثبت تک‌نوتیفیکیشن در پس‌زمینه با ارزیابی ریت‌لیمیت و زمان‌بندی (توسط Job فراخوانی می‌شود)
     */
    public function processSinglePersist(
        int $uid, string $type, string $title, string $message,
        ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt
    ): bool {
        // ۱. ارزیابی محدودیت نرخ ارسال (Rate Limit)
        if (!$this->checkRateLimit($uid)) {
            return false;
        }

        // ۲. ارزیابی و حل زمان ارسال زمان‌بندی شده
        $resTime = $this->resolveScheduledTime($uid, $priority, $scheduledAt);

        // ۳. ثبت فیزیکی در دیتابیس
        return (bool)$this->persistInAppNotification(
            $uid, $type, $title, $message, $data,
            $actionUrl, $actionText, $priority, null, null, null, $resTime
        );
    }

    // --- Proxy Calls to Tracking Service ---

    public function latest(int $userId, int $limit = 10): array
    {
        return $this->tracker->getLatestForUser($userId, $limit);
    }

    public function getUserNotifications(int $userId, bool $onlyUnread = false, int $limit = 20, int $offset = 0): array
    {
        return $this->tracker->getUserNotifications($userId, $onlyUnread, $limit, $offset);
    }

    public function countUserNotifications(int $userId, bool $onlyUnread = false): int
    {
        return $this->tracker->countUserNotifications($userId, $onlyUnread);
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->tracker->getUnreadCount($userId);
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        return $this->tracker->markAsRead($notificationId, $userId);
    }

    public function markAllAsRead(int $userId): bool
    {
        return $this->tracker->markAllAsRead($userId);
    }

    public function markAllAsReadCount(int $userId): int
    {
        // برای سازگاری، ابتدا رکوردها را آپدیت کرده و بعد خروجی را بازمی‌گرداند
        $this->tracker->markAllAsRead($userId);
        return 1; // ساده‌سازی برای حفظ API
    }

    public function recordClick(int $notificationId, int $userId): bool
    {
        return $this->model->recordClick($notificationId, $userId);
    }

    public function archive(int $notificationId, int $userId): bool
    {
        return $this->tracker->archive($notificationId, $userId);
    }

    public function softDelete(int $notificationId, int $userId): bool
    {
        return $this->tracker->softDelete($notificationId, $userId);
    }

    public function invalidateUnreadCache(int $userId): void
    {
        $this->tracker->invalidateUnreadCache($userId);
    }

    public function getNewNotifications(int $userId, int $lastId, int $limit = 20): array
    {
        if ($lastId === 0) {
            return ['success' => true, 'notifications' => [], 'unread_count' => $this->getUnreadCount($userId)];
        }
        $notifications = $this->tracker->getNewNotificationsAfterId($userId, $lastId, $limit);
        if (empty($notifications)) {
            return ['success' => true, 'notifications' => [], 'unread_count' => $this->getUnreadCount($userId)];
        }
        return [
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $this->getUnreadCount($userId),
            'last_id'       => end($notifications)->id,
        ];
    }

    // متدهای مدیریت ترجیحات (Preferences) به NotificationPreferenceService منتقل شدند.

    // --- Proxy Calls to Template Service ---

    public function getTemplate(string $templateKey): array
    {
        return $this->templateService->getTemplate($templateKey);
    }

    public function renderTemplate(string $templateKey, array $vars = []): array
    {
        return $this->templateService->renderTemplate($templateKey, $vars);
    }

    public function getAllTemplatesWithVariables(): array
    {
        return $this->templateService->getAllTemplatesWithVariables();
    }

    public function saveTemplateOverride(string $key, string $title, string $message): bool
    {
        return $this->templateService->saveTemplateOverride($key, $title, $message);
    }

    public function deleteTemplateOverride(string $key): bool
    {
        return $this->templateService->deleteTemplateOverride($key);
    }

    // متدهای مربوط به آمار (Analytics) به NotificationAnalyticsService منتقل شدند.

    // --- Common/Shortcut Methods ---

    // متد saveUserToken به FcmService منتقل شد.

    public function findForUser(int $notificationId, int $userId): ?object
    {
        $notification = $this->model->find($notificationId);
        if (!$notification || (int)$notification->user_id !== $userId) {
            return null;
        }
        return $notification;
    }

    public function getUsersBySegment(string $segment, array $filters = []): array
    {
        return $this->model->getUsersBySegment($segment, $filters);
    }

    public function getAvailableSegments(): array
    {
        return [
            'all'          => 'همه کاربران فعال',
            'kyc_verified' => 'کاربران با KYC تأیید‌شده',
            'kyc_pending'  => 'کاربران در انتظار KYC',
            'kyc_none'     => 'کاربران بدون KYC',
            'level_silver' => 'کاربران سطح نقره',
            'level_gold'   => 'کاربران سطح طلا',
            'level_vip'    => 'کاربران VIP',
            'new_users'    => 'کاربران جدید (۳۰ روز اخیر)',
            'inactive'     => 'کاربران غیرفعال (۶۰+ روز)',
            'custom'       => 'سفارشی (با فیلتر)',
        ];
    }

    // --- Helpers & Specialized Send Handlers ---

    public function depositSuccess(int $userId, float $amount, string $currency): ?int
    {
        return $this->sendFromTemplate($userId, 'deposit', [
            'amount'   => format_amount($amount),
            'currency' => strtoupper($currency),
        ], Notification::PRIORITY_HIGH, url('/wallet'), 'مشاهده کیف پول');
    }

    public function withdrawalApproved(int $userId, float $amount, string $currency): ?int
    {
        $id = $this->sendFromTemplate($userId, 'withdrawal', [
            'amount'   => format_amount($amount),
            'currency' => strtoupper($currency),
        ], Notification::PRIORITY_HIGH, url('/wallet/history'), 'مشاهده تاریخچه');

        $this->sendWithdrawalSms($userId, $amount, $currency);
        return $id;
    }

    public function withdrawalRejected(int $userId, float $amount, string $reason): ?int
    {
        return $this->sendFromTemplate($userId, 'withdrawal_rejected', [
            'amount' => format_amount($amount),
            'reason' => $reason,
        ], Notification::PRIORITY_HIGH, url('/wallet/history'), 'مشاهده جزئیات');
    }

    public function kycVerified(int $userId): ?int
    {
        return $this->sendFromTemplate($userId, 'kyc_approved', [],
            Notification::PRIORITY_HIGH, url('/dashboard'), 'ورود به داشبورد');
    }

    public function kycRejected(int $userId, string $reason): ?int
    {
        return $this->sendFromTemplate($userId, 'kyc_rejected', [
            'reason' => $reason,
        ], Notification::PRIORITY_URGENT, url('/kyc/upload'), 'ارسال مجدد مدارک');
    }

    public function securityAlert(int $userId, string $message, string $ip): ?int
    {
        $id = $this->sendFromTemplate($userId, 'security', [
            'message' => $message,
            'ip'      => $ip,
        ], Notification::PRIORITY_URGENT, url('/profile/security'), 'بررسی حساب');

        $this->sendSecuritySms($userId, $message);
        return $id;
    }

    public function sendToAdmins(string $type, string $title, string $message, ?array $data = null, string $priority = 'normal'): int
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
                foreach ($allowedAdminIds as $adminId) {
                    $this->tracker->invalidateUnreadCache($adminId);
                }
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


    public function newTaskAvailable(int $userId, string $taskTitle): ?int
    {
        return $this->sendFromTemplate($userId, 'task', [
            'task_title' => $taskTitle,
        ], Notification::PRIORITY_NORMAL, url('/tasks'), 'مشاهده تسک‌ها', 'task_available');
    }

    public function lotteryWinner(int $userId, float $amount): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_LOTTERY,
            '🎉 تبریک! برنده شدید!',
            'شما برنده قرعه‌کشی شدید! مبلغ ' . format_amount($amount) . ' به کیف پول شما واریز شد.',
            ['amount' => $amount],
            url('/wallet'),
            'مشاهده کیف پول',
            Notification::PRIORITY_URGENT,
            date('Y-m-d H:i:s', strtotime('+7 days'))
        );
    }

    public function referralEarning(int $userId, float $amount, string $referredUserName): ?int
    {
        return $this->sendFromTemplate($userId, 'referral', [
            'referred_user' => $referredUserName,
            'amount'        => format_amount($amount),
        ], Notification::PRIORITY_NORMAL, url('/referral'), 'مشاهده زیرمجموعه‌ها', 'referral_earning');
    }

    public function investmentCompleted(int $userId, float $profit, float $total): ?int
    {
        return $this->sendFromTemplate($userId, 'investment_completed', [
            'profit' => format_amount($profit),
            'total'  => format_amount($total),
        ], Notification::PRIORITY_HIGH, url('/investments'), 'مشاهده سرمایه‌گذاری‌ها');
    }

    private function sendSecuritySms(int $userId, string $message): void
    {
        try {
            $this->queue->pushUnique(
                \App\Jobs\ProcessNotificationJob::class,
                [
                    'channel' => 'sms',
                    'user_ids' => [$userId],
                    'sms_type' => 'security',
                    'message' => $message,
                ],
                'notif:sms_sec:' . $userId . '_' . time()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function sendWithdrawalSms(int $userId, float $amount, string $currency): void
    {
        try {
            $this->queue->pushUnique(
                \App\Jobs\ProcessNotificationJob::class,
                [
                    'channel' => 'sms',
                    'user_ids' => [$userId],
                    'sms_type' => 'withdrawal',
                    'amount' => $amount,
                    'currency' => $currency,
                ],
                'notif:sms_withdraw:' . $userId . '_' . time()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    public function prefetchPreferences(array $userIds): void
    {
        $this->policyService->prefetchPreferences($userIds);
    }
}
