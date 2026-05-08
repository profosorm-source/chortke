<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\NotificationPreference;
use Core\Cache;
use Core\RateLimiter;
use App\Services\EmailService;
use App\Services\Notification\FcmService;

use App\Contracts\LoggerInterface;
/**
 * NotificationService — Orchestrator برای سیستم نوتیفیکیشن
 */
class NotificationService extends \App\Services\BaseService
{
    private const RATE_MAX_PER_USER_PER_HOUR = 20;
    private const RATE_WINDOW_MINUTES        = 60;
    private const UNREAD_CACHE_PREFIX = 'notif_unread:';
    private const UNREAD_CACHE_TTL   = 5;

    // Template constants
    private const TEMPLATE_CACHE_TTL = 30;
    private const TEMPLATE_CACHE_PREFIX = 'notif_tpl:';

    // Analytics constants
    private const ANALYTICS_CACHE_TTL = 15;
    private const ANALYTICS_CACHE_PREFIX = 'notif_analytics:';

    public function __construct(
        private Notification $model,
        private Notification $notificationModel,
        private NotificationPreference $prefModel,
        private NotificationDispatcher $dispatcher,
        private FcmService $fcmService,
        protected LoggerInterface $logger,
        private RateLimiter $rateLimiter,
        private Cache $cache,
        private ?EmailService $emailService = null
    ) {
        parent::__construct($logger);
    }

    /**
     * ارسال نوتیفیکیشن به یک کاربر
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
        if (!$this->checkRateLimit($userId, $type)) {
            $this->logger->info('notif.rate_limited', ['user_id' => $userId, 'type' => $type]);
            return null;
        }

        if ($scheduledAt === null && $priority !== Notification::PRIORITY_URGENT) {
            if ($this->prefModel->isInDndMode($userId)) {
                $scheduledAt = $this->getNextDndEndTime($userId);
                $this->logger->info('notif.dnd_deferred', ['user_id' => $userId, 'scheduled_at' => $scheduledAt]);
            }
        }

        $notifId = null;

        try {
            if ($this->prefModel->isInAppEnabled($userId, $type)) {
                $notifId = $this->notificationModel->create([
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
                    $this->invalidateUnreadCache($userId);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('notif.in_app_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        if ($scheduledAt === null && $this->prefModel->isPushEnabled($userId, $type)) {
            try {
                $this->dispatcher->dispatch(
                    'fcm',
                    $userId,
                    $title,
                    $message,
                    array_merge($data ?? [], ['type' => $type, 'notif_id' => (string)($notifId ?? '')]),
                    $imageUrl,
                    $actionUrl
                );
            } catch (\Throwable $e) {
                $this->logger->warning('notif.push_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }

        return $notifId;
    }

    /**
     * ارسال با استفاده از template system
     */
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
        $rendered = $this->renderTemplate($templateKey, $vars);

        $type = explode('_', $templateKey)[0];
        if (!defined(Notification::class . '::TYPE_' . strtoupper($type))) {
            $type = Notification::TYPE_SYSTEM;
        }

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

    /**
     * ارسال به همه کاربران active
     */
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
        $users = $this->model->getActiveUsersIds();

        return $this->sendBulkToUsers(
            $users, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt
        );
    }

    /**
     * ارسال به segment کاربران
     */
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
        $users = $this->getUsersBySegment($segment, $filters);

        $result = $this->sendBulkToUsers(
            $users, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt
        );

        $this->logger->info('notif.send_to_segment', array_merge($result, [
            'segment' => $segment,
            'type'    => $type,
        ]));

        return $result;
    }

    /**
     * ارسال bulk به آرایه‌ای از user IDs
     */
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

    public function latest(int $userId, int $limit = 10): array
    {
        return $this->notificationModel->getLatestForUser($userId, $limit);
    }

    public function getUserNotifications(int $userId, bool $onlyUnread = false, int $limit = 20, int $offset = 0): array
    {
        return $this->notificationModel->getUserNotifications($userId, $onlyUnread, $limit, $offset);
    }

    public function countUserNotifications(int $userId, bool $onlyUnread = false): int
    {
        return $this->notificationModel->countUserNotifications($userId, $onlyUnread);
    }

    public function getPreferences(int $userId): object
    {
        return $this->prefModel->getOrCreate($userId);
    }

    public function updatePreferences(int $userId, array $data): bool
    {
        $allowedFields = $this->prefModel->getAllowedFields();
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        return $this->prefModel->updateForUser($userId, $updateData);
    }

    public function markAsRead(int $notificationId, int $userId): bool
    {
        $result = $this->notificationModel->markAsRead($notificationId, $userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function markAllAsRead(int $userId): bool
    {
        $result = $this->notificationModel->markAllAsRead($userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function markAllAsReadCount(int $userId): int
    {
        $count = $this->notificationModel->markAllAsReadCount($userId);
        if ($count > 0) {
            $this->invalidateUnreadCache($userId);
        }
        return $count;
    }

    public function recordClick(int $notificationId, int $userId): bool
    {
        return $this->notificationModel->recordClick($notificationId, $userId);
    }

    public function archive(int $notificationId, int $userId): bool
    {
        $result = $this->notificationModel->archive($notificationId, $userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function softDelete(int $notificationId, int $userId): bool
    {
        $result = $this->notificationModel->softDelete($notificationId, $userId);
        if ($result) {
            $this->invalidateUnreadCache($userId);
        }
        return $result;
    }

    public function findForUser(int $notificationId, int $userId): ?object
    {
        $notification = $this->notificationModel->find($notificationId);
        if (!$notification || (int)$notification->user_id !== $userId) {
            return null;
        }
        return $notification;
    }

    public function saveUserToken(int $userId, string $token, string $platform = 'web'): bool
    {
        return $this->fcmService->saveUserToken($userId, $token, $platform);
    }

    public function getNewNotifications(int $userId, int $lastId, int $limit = 20): array
    {
        if ($lastId === 0) {
            return [
                'success'       => true,
                'notifications' => [],
                'unread_count'  => $this->getUnreadCount($userId),
            ];
        }

        $notifications = $this->notificationModel->getNewNotificationsAfterId($userId, $lastId, $limit);

        if (empty($notifications)) {
            return [
                'success'       => true,
                'notifications' => [],
                'unread_count'  => $this->getUnreadCount($userId),
            ];
        }

        $this->invalidateUnreadCache($userId);

        return [
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $this->getUnreadCount($userId),
            'last_id'       => end($notifications)->id,
        ];
    }

    public function getUnreadCount(int $userId): int
    {
        $cacheKey = self::UNREAD_CACHE_PREFIX . $userId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (int)$cached;
        }

        $count = $this->notificationModel->countUnread($userId);
        $this->cache->put($cacheKey, $count, self::UNREAD_CACHE_TTL);

        return $count;
    }

    public function invalidateUnreadCache(int $userId): void
    {
        $this->cache->forget(self::UNREAD_CACHE_PREFIX . $userId);
    }

    // --- Template Methods ---

    /**
     * رندر یک template با متغیرها
     */
    public function renderTemplate(string $templateKey, array $vars = []): array
    {
        $template = $this->getTemplate($templateKey);

        return [
            'title'   => $this->interpolate($template['title'],   $vars),
            'message' => $this->interpolate($template['message'], $vars),
        ];
    }

    /**
     * دریافت template — DB override > default
     */
    public function getTemplate(string $templateKey): array
    {
        $cacheKey = self::TEMPLATE_CACHE_PREFIX . $templateKey;

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $dbTemplate = $this->model->getTemplateFromDb($templateKey);
        if ($dbTemplate) {
            $result = [
                'title'     => $dbTemplate->title,
                'message'   => $dbTemplate->message,
                'variables' => json_decode((string)($dbTemplate->variables ?? '{}'), true) ?: [],
            ];
            $this->cache->put($cacheKey, $result, self::TEMPLATE_CACHE_TTL);
            return $result;
        }

        $default = $this->getDefaultTemplate($templateKey);
        $this->cache->put($cacheKey, $default, self::TEMPLATE_CACHE_TTL);
        return $default;
    }

    private function getDefaultTemplate(string $templateKey): array
    {
        $defaults = [
            'deposit' => [
                'title'     => 'واریز موفق ✅',
                'message'   => 'مبلغ {{amount}} {{currency}} با موفقیت به کیف پول شما واریز شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal' => [
                'title'     => 'برداشت تأیید شد 💸',
                'message'   => 'درخواست برداشت {{amount}} {{currency}} تأیید و پردازش شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal_rejected' => [
                'title'     => 'برداشت رد شد ❌',
                'message'   => 'درخواست برداشت {{amount}} رد شد. دلیل: {{reason}}. مبلغ به کیف پول بازگشت.',
                'variables' => ['amount', 'reason'],
            ],
            'task' => [
                'title'     => 'تسک جدید 📋',
                'message'   => 'تسک جدید «{{task_title}}» برای شما در دسترس است.',
                'variables' => ['task_title'],
            ],
            'kyc_approved' => [
                'title'     => 'احراز هویت تأیید شد ✅',
                'message'   => 'احراز هویت شما تأیید شد. اکنون می‌توانید از تمام امکانات سایت استفاده کنید.',
                'variables' => [],
            ],
            'kyc_rejected' => [
                'title'     => 'احراز هویت رد شد ❌',
                'message'   => 'احراز هویت شما رد شد. دلیل: {{reason}}. لطفاً مدارک را مجدداً ارسال کنید.',
                'variables' => ['reason'],
            ],
            'lottery_winner' => [
                'title'     => '🎉 تبریک! برنده شدید!',
                'message'   => 'شما برنده قرعه‌کشی شدید! مبلغ {{amount}} به کیف پول شما واریز شد.',
                'variables' => ['amount'],
            ],
            'referral' => [
                'title'     => 'کمیسیون معرفی 💰',
                'message'   => 'از فعالیت «{{referred_user}}» مبلغ {{amount}} کمیسیون دریافت کردید.',
                'variables' => ['referred_user', 'amount'],
            ],
            'security' => [
                'title'     => '⚠️ هشدار امنیتی',
                'message'   => '{{message}}',
                'variables' => ['message', 'ip'],
            ],
            'investment_completed' => [
                'title'     => 'سرمایه‌گذاری تکمیل شد 📈',
                'message'   => 'سرمایه‌گذاری شما به پایان رسید. سود: {{profit}} — مجموع: {{total}}.',
                'variables' => ['profit', 'total'],
            ],
            'system' => [
                'title'     => '{{title}}',
                'message'   => '{{message}}',
                'variables' => ['title', 'message'],
            ],
        ];

        return $defaults[$templateKey] ?? $defaults['system'];
    }

    /**
     * لیست همه template‌ها با متغیرها
     */
    public function getAllTemplatesWithVariables(): array
    {
        $templates = [];
        $defaults = $this->getDefaultTemplates();

        foreach ($defaults as $key => $template) {
            $dbTemplate = $this->model->getTemplateFromDb($key);
            if ($dbTemplate) {
                $templates[$key] = [
                    'title'     => $dbTemplate->title,
                    'message'   => $dbTemplate->message,
                    'variables' => json_decode((string)($dbTemplate->variables ?? '{}'), true) ?: [],
                    'is_custom' => true,
                ];
            } else {
                $templates[$key] = array_merge($template, ['is_custom' => false]);
            }
        }

        return $templates;
    }

    /**
     * ذخیره override template
     */
    public function saveTemplateOverride(string $key, string $title, string $message): bool
    {
        return $this->model->saveTemplateOverride($key, $title, $message);
    }

    /**
     * حذف override template
     */
    public function deleteTemplateOverride(string $key): bool
    {
        return $this->model->deleteTemplateOverride($key);
    }

    private function getDefaultTemplates(): array
    {
        return [
            'deposit' => [
                'title'     => 'واریز موفق ✅',
                'message'   => 'مبلغ {{amount}} {{currency}} با موفقیت به کیف پول شما واریز شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal' => [
                'title'     => 'برداشت تأیید شد 💸',
                'message'   => 'درخواست برداشت {{amount}} {{currency}} تأیید و پردازش شد.',
                'variables' => ['amount', 'currency'],
            ],
            'withdrawal_rejected' => [
                'title'     => 'برداشت رد شد ❌',
                'message'   => 'درخواست برداشت {{amount}} رد شد. دلیل: {{reason}}. مبلغ به کیف پول بازگشت.',
                'variables' => ['amount', 'reason'],
            ],
            'task' => [
                'title'     => 'تسک جدید 📋',
                'message'   => 'تسک جدید «{{task_title}}» برای شما در دسترس است.',
                'variables' => ['task_title'],
            ],
            'kyc_approved' => [
                'title'     => 'احراز هویت تأیید شد ✅',
                'message'   => 'احراز هویت شما تأیید شد. اکنون می‌توانید از تمام امکانات سایت استفاده کنید.',
                'variables' => [],
            ],
            'kyc_rejected' => [
                'title'     => 'احراز هویت رد شد ❌',
                'message'   => 'احراز هویت شما رد شد. دلیل: {{reason}}. لطفاً مدارک را مجدداً ارسال کنید.',
                'variables' => ['reason'],
            ],
            'lottery_winner' => [
                'title'     => '🎉 تبریک! برنده شدید!',
                'message'   => 'شما برنده قرعه‌کشی شدید! مبلغ {{amount}} به کیف پول شما واریز شد.',
                'variables' => ['amount'],
            ],
            'referral' => [
                'title'     => 'کمیسیون معرفی 💰',
                'message'   => 'از فعالیت «{{referred_user}}» مبلغ {{amount}} کمیسیون دریافت کردید.',
                'variables' => ['referred_user', 'amount'],
            ],
            'security' => [
                'title'     => '⚠️ هشدار امنیتی',
                'message'   => '{{message}}',
                'variables' => ['message', 'ip'],
            ],
            'investment_completed' => [
                'title'     => 'سرمایه‌گذاری تکمیل شد 📈',
                'message'   => 'سرمایه‌گذاری شما به پایان رسید. سود: {{profit}} — مجموع: {{total}}.',
                'variables' => ['profit', 'total'],
            ],
            'system' => [
                'title'     => '{{title}}',
                'message'   => '{{message}}',
                'variables' => ['title', 'message'],
            ],
        ];
    }

    private function interpolate(string $text, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $text = str_replace("{{{$key}}}", (string)$value, $text);
        }
        return $text;
    }

    // --- Analytics Methods ---

    /**
     * Overview — KPI های اصلی داشبورد
     */
    public function getAnalyticsOverview(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "overview:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->model->getOverviewStats($days) ?: []
        );
    }

    /**
     * آمار per-type با breakdown کامل
     */
    public function getAnalyticsByType(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "by_type:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getAdminStatsByType($days)
        );
    }

    /**
     * روند روزانه (sent / read / click)
     */
    public function getAnalyticsDailyTrend(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "daily:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getDailyStats($days)
        );
    }

    /**
     * آمار per-segment (KYC / level / status)
     */
    public function getAnalyticsSegmentStats(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "segment:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->notificationModel->getStatsBySegment($days)
        );
    }

    /**
     * قیف کامل: Sent → Read → Clicked
     */
    public function getAnalyticsFunnelStats(int $days = 30): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "funnel:{$days}",
            self::ANALYTICS_CACHE_TTL,
            fn() => $this->model->getFunnelStats($days) ?: []
        );
    }

    /**
     * Notification Fatigue — کاربران با انباشت بالا
     */
    public function getAnalyticsFatigueReport(int $threshold = 20): array
    {
        return $this->cache->remember(
            self::ANALYTICS_CACHE_PREFIX . "fatigue:{$threshold}",
            self::ANALYTICS_CACHE_TTL,
            function () use ($threshold) {
                $users = $this->notificationModel->getHighUnreadUsers($threshold, 50);
                $summary = $this->model->getFatigueSummary($threshold);

                return [
                    'summary' => $summary ?: [],
                    'users'   => $users ?: [],
                ];
            }
        );
    }

    // --- Template Shortcuts ---

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

    public function newTaskAvailable(int $userId, string $taskTitle): ?int
    {
        return $this->sendFromTemplate($userId, 'task', [
            'task_title' => $taskTitle,
        ], Notification::PRIORITY_NORMAL, url('/tasks'), 'مشاهده تسک‌ها', 'task_available');
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

    public function securityAlert(int $userId, string $message, string $ip): ?int
    {
        $id = $this->sendFromTemplate($userId, 'security', [
            'message' => $message,
            'ip'      => $ip,
        ], Notification::PRIORITY_URGENT, url('/profile/security'), 'بررسی حساب');

        $this->sendSecuritySms($userId, $message);
        return $id;
    }

    public function investmentCompleted(int $userId, float $profit, float $total): ?int
    {
        return $this->sendFromTemplate($userId, 'investment_completed', [
            'profit' => format_amount($profit),
            'total'  => format_amount($total),
        ], Notification::PRIORITY_HIGH, url('/investments'), 'مشاهده سرمایه‌گذاری‌ها');
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

    private function sendBulkToUsers(
        array   $users,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data,
        ?string $actionUrl,
        ?string $actionText,
        string  $priority,
        ?string $scheduledAt
    ): array {
        $sent    = 0;
        $skipped = 0;

        foreach ($users as $u) {
            $ok = $this->send(
                (int)$u->id, $type, $title, $message,
                $data, $actionUrl, $actionText, $priority,
                null, null, null, $scheduledAt
            );
            $ok ? $sent++ : $skipped++;
        }

        $this->logger->info('notif.bulk_sent', ['sent' => $sent, 'skipped' => $skipped, 'type' => $type]);
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    private function checkRateLimit(int $userId, string $type): bool
    {
        $key = "notif_rl_user_{$userId}";
        return $this->rateLimiter->attempt($key, self::RATE_MAX_PER_USER_PER_HOUR, self::RATE_WINDOW_MINUTES);
    }

    private function getNextDndEndTime(int $userId): string
    {
        try {
            $prefs = $this->prefModel->getOrCreate($userId);
            $end   = $prefs->dnd_end ?? '07:00:00';
            $endTs = strtotime(date('Y-m-d') . ' ' . $end);
            if ($endTs <= time()) {
                $endTs = strtotime('+1 day', $endTs);
            }
            return date('Y-m-d H:i:s', $endTs);
        } catch (\Throwable) {
            return date('Y-m-d H:i:s', strtotime('+8 hours'));
        }
    }

    private function sendSecuritySms(int $userId, string $message): void
    {
        if (!$this->prefModel->isSmsEnabled($userId, 'security')) {
            return;
        }
        try {
            $this->dispatcher->dispatch(
                'sms',
                $userId,
                'هشدار امنیتی',
                $message
            );
        } catch (\Throwable $e) {
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function sendWithdrawalSms(int $userId, float $amount, string $currency): void
    {
        if (!$this->prefModel->isSmsEnabled($userId, 'withdrawal')) {
            return;
        }
        try {
            $this->dispatcher->dispatch(
                'sms',
                $userId,
                'برداشت تأیید شد',
                "برداشت {$amount} {$currency} تأیید شد"
            );
        } catch (\Throwable $e) {
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Batch aggregation آمار نوتیفیکیشن‌ها برای dash boards
     * این متد هر ساعت توسط cron فراخوانی می‌شود
     */
    public function runBatchAggregation(): array
    {
        try {
            $processed = 0;
            
            // aggregate stats by type
            $types = $this->notificationModel->distinct('type')->get();
            foreach ($types as $type) {
                $this->cache->put(
                    self::ANALYTICS_CACHE_PREFIX . "type:{$type}",
                    $this->notificationModel->getAdminStatsByType(30),
                    self::ANALYTICS_CACHE_TTL
                );
                $processed++;
            }

            // aggregate daily stats
            $this->cache->put(
                self::ANALYTICS_CACHE_PREFIX . "daily:30",
                $this->notificationModel->getDailyStats(30),
                self::ANALYTICS_CACHE_TTL
            );
            $processed++;

            // aggregate funnel stats
            $this->cache->put(
                self::ANALYTICS_CACHE_PREFIX . "funnel:30",
                $this->model->getFunnelStats(30),
                self::ANALYTICS_CACHE_TTL
            );
            $processed++;

            $this->logger->info('notif.batch_aggregation', ['processed' => $processed]);
            return ['processed' => $processed, 'success' => true];
        } catch (\Throwable $e) {
            $this->logger->error('notif.batch_aggregation_failed', ['error' => $e->getMessage()]);
            return ['processed' => 0, 'success' => false, 'error' => $e->getMessage()];
        }
    }
}

