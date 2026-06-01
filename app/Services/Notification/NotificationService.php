<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Contracts\LoggerInterface;
use App\Contracts\NotificationServiceInterface;
use App\Services\Settings\AppSettings;
use Core\RateLimiter;
use Core\EventDispatcher;
use Core\Queue;
use App\Models\Notification;
use App\Contracts\OutboxServiceInterface;

/**
 * NotificationService - Lean Orchestrator
 * 
 * این سرویس پس از ریفکتور، فقط مسئولیت هماهنگی ارسال را دارد.
 * کارهای سنگین رهگیری (Tracking) و آمار (Analytics) به DomainActivityListener منتقل شده است.
 */
class NotificationService implements NotificationServiceInterface
{
    private \App\Contracts\LoggerInterface $logger;
    private NotificationPolicyService $policyService;
    private Notification $model;
    private NotificationTracker $tracker;
    private Queue $queue;
    private ?OutboxServiceInterface $outbox;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        NotificationPolicyService $policyService,
        Notification $model,
        NotificationTracker $tracker,
        Queue $queue,
        ?OutboxServiceInterface $outbox = null
    ) {        $this->logger = $logger;
        $this->policyService = $policyService;
        $this->model = $model;
        $this->tracker = $tracker;
        $this->queue = $queue;
        $this->outbox = $outbox;
}

    /**
     * ارسال هوشمند نوتیفیکیشن با بررسی ترجیحات و ریت‌لیمیت
     */
    public function send(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
        ?string $actionUrl = null,
        ?string $actionText = null,
        string $priority = 'normal',
        ?string $expiresAt = null,
        ?string $imageUrl = null,
        ?string $groupKey = null,
        ?string $scheduledAt = null
    ): ?int {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SendNotificationJob::class);
        return $job->handle($userId, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $expiresAt, $imageUrl, $groupKey, $scheduledAt);
    }

    /**
     * Dispatch notification to a specific channel directly (used for fallbacks)
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\DispatchNotificationJob::class);
        return $job->handle($channel, $userId, $title, $message, $data, $imageUrl, $actionUrl, $actionText, $priority);
    }

    /**
     * ارسال انبوه به صورت بهینه
     */
    public function sendBulk(array $userIds, string $type, string $title, string $message, array $data = [], ?string $actionUrl = null): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SendBulkNotificationJob::class);
        return $job->handle($userIds, $type, $title, $message, $data, $actionUrl);
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

        // 4. Dispatch External Channels (FCM, SMS, Email, etc.)
        $this->dispatchRemoteNotifications(
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
    private function dispatchRemoteNotifications(
        int $userId, string $type, string $title, string $message, ?array $data,
        ?string $actionUrl, ?string $imageUrl, ?string $scheduledAt, ?int $notifId
    ): void {
        if ($scheduledAt !== null) {
            return;
        }

        $allowedChannels = $this->policyService->getAllowedChannels($userId, $type);
        
        foreach ($allowedChannels as $channel) {
            $idempotencyKey = $notifId ? $channel . '_' . $notifId : uniqid($channel . '_', true);
            $messageId = $notifId ? (string)$notifId : uniqid('msg_', true);

            try {
                $payload = [
                    'channel' => $channel,
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
                    "notif:{$channel}:{$messageId}:{$userId}",
                    null,
                    0,
                    86400
                );
            } catch (\Throwable $e) {
                $this->logger->warning("notif.{$channel}_queue_failed_falling_back_to_outbox", ['user_id' => $userId, 'error' => $e->getMessage()]);

                $outboxPayload = [
                    'notification' => [
                        'method' => 'dispatch',
                        'args' => [
                            $channel,
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
                        'fallback' => "notification_{$channel}",
                        'queue_error' => $e->getMessage(),
                    ],
                ];

                if ($this->outbox) {
                    try {
                        $this->outbox->record(
                            'notification',
                            (string)$userId,
                            "{$channel}_failed",
                            $outboxPayload,
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SendFromTemplateNotificationJob::class);
        return $job->handle($userId, $templateKey, $vars, $priority, $actionUrl, $actionText, $groupKey, $scheduledAt);
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SendToAllNotificationJob::class);
        return $job->handle($title, $message, $type, $actionUrl, $actionText, $priority, $data, $scheduledAt);
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SendToSegmentNotificationJob::class);
        return $job->handle($segment, $title, $message, $type, $actionUrl, $actionText, $priority, $data, $scheduledAt, $filters);
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\ProcessSinglePersistNotificationJob::class);
        return $job->handle($uid, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt);
    }


    // --- Proxy Calls to Tracking Service ---

    public function latest(int $userId, int $limit = 10): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\LatestNotificationJob::class);
        return $job->handle($userId, $limit);
    }

    public function getUserNotifications(int $userId, bool $onlyUnread = false, int $limit = 20, int $offset = 0): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\GetUserNotificationsNotificationJob::class);
        return $job->handle($userId, $onlyUnread, $limit, $offset);
    }

    public function countUserNotifications(int $userId, bool $onlyUnread = false): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\CountUserNotificationsNotificationJob::class);
        return $job->handle($userId, $onlyUnread);
    }

    public function getUnreadCount(int $userId): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\GetUnreadCountNotificationJob::class);
        return $job->handle($userId);
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\MarkAsReadNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function markAllAsRead(int $userId): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\MarkAllAsReadNotificationJob::class);
        return $job->handle($userId);
    }

    public function markAllAsReadCount(int $userId): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\MarkAllAsReadCountNotificationJob::class);
        return $job->handle($userId);
    }

    public function recordClick(int $notificationId, int $userId): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\RecordClickNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function archive(int $notificationId, int $userId): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\ArchiveNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function softDelete(int $notificationId, int $userId): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SoftDeleteNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function invalidateUnreadCache(int $userId): void
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\InvalidateUnreadCacheNotificationJob::class);
        $job->handle($userId);
    }

    public function getNewNotifications(int $userId, int $lastId, int $limit = 20): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\GetNewNotificationsNotificationJob::class);
        return $job->handle($userId, $lastId, $limit);
    }

    // متدهای مدیریت ترجیحات (Preferences) به NotificationPreferenceService منتقل شدند.

    // --- Proxy Calls to Template Service ---

    public function getTemplate(string $templateKey): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\GetTemplateNotificationJob::class);
        return $job->handle($templateKey);
    }

    public function renderTemplate(string $templateKey, array $vars = []): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\RenderTemplateNotificationJob::class);
        return $job->handle($templateKey, $vars);
    }

    public function getAllTemplatesWithVariables(): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\GetAllTemplatesWithVariablesNotificationJob::class);
        return $job->handle();
    }

    public function saveTemplateOverride(string $key, string $title, string $message): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SaveTemplateOverrideNotificationJob::class);
        return $job->handle($key, $title, $message);
    }

    public function deleteTemplateOverride(string $key): bool
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\DeleteTemplateOverrideNotificationJob::class);
        return $job->handle($key);
    }

    // متدهای مربوط به آمار (Analytics) به NotificationAnalyticsService منتقل شدند.

    // --- Common/Shortcut Methods ---

    // متد saveUserToken به FcmService منتقل شد.

    public function findForUser(int $notificationId, int $userId): ?object
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\FindForUserNotificationJob::class);
        return $job->handle($notificationId, $userId);
    }

    public function getUsersBySegment(string $segment, array $filters = []): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\GetUsersBySegmentNotificationJob::class);
        return $job->handle($segment, $filters);
    }

    public function getAvailableSegments(): array
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\GetAvailableSegmentsNotificationJob::class);
        return $job->handle();
    }

    // --- Helpers & Specialized Send Handlers ---

    public function depositSuccess(int $userId, float $amount, string $currency): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\DepositSuccessNotificationJob::class);
        return $job->handle($userId, $amount, $currency);
    }

    public function withdrawalApproved(int $userId, float $amount, string $currency): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\WithdrawalApprovedNotificationJob::class);
        return $job->handle($userId, $amount, $currency);
    }

    public function withdrawalRejected(int $userId, float $amount, string $reason): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\WithdrawalRejectedNotificationJob::class);
        return $job->handle($userId, $amount, $reason);
    }

    public function kycVerified(int $userId): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\KycVerifiedNotificationJob::class);
        return $job->handle($userId);
    }

    public function kycRejected(int $userId, string $reason): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\KycRejectedNotificationJob::class);
        return $job->handle($userId, $reason);
    }

    public function securityAlert(int $userId, string $message, string $ip): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SecurityAlertNotificationJob::class);
        return $job->handle($userId, $message, $ip);
    }

    public function sendToAdmins(string $type, string $title, string $message, ?array $data = null, string $priority = 'normal'): int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\SendToAdminsNotificationJob::class);
        return $job->handle($type, $title, $message, $data, $priority);
    }


    public function newTaskAvailable(int $userId, string $taskTitle): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\NewTaskAvailableNotificationJob::class);
        return $job->handle($userId, $taskTitle);
    }

    public function lotteryWinner(int $userId, float $amount): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\LotteryWinnerNotificationJob::class);
        return $job->handle($userId, $amount);
    }

    public function referralEarning(int $userId, float $amount, string $referredUserName): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\ReferralEarningNotificationJob::class);
        return $job->handle($userId, $amount, $referredUserName);
    }

    public function investmentCompleted(int $userId, float $profit, float $total): ?int
    {
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\InvestmentCompletedNotificationJob::class);
        return $job->handle($userId, $profit, $total);
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
        $job = \Core\Container::getInstance()->make(\App\Jobs\Notification\PrefetchPreferencesNotificationJob::class);
        $job->handle($userIds);
    }
}
