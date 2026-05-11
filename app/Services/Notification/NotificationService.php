<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Models\Notification;
use Core\RateLimiter;
use App\Services\EmailService;
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

    public function __construct(
        private Notification $model,
        private NotificationDispatcher $dispatcher,
        private FcmService $fcmService,
        protected LoggerInterface $logger,
        private RateLimiter $rateLimiter,
        private NotificationTemplateService $templateService,
        private NotificationPreferenceService $preferenceService,
        private NotificationTracker $tracker,
        private NotificationAnalyticsService $analyticsService,
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
        if (!$this->checkRateLimit($userId)) {
            $this->logger->info('notif.rate_limited', ['user_id' => $userId, 'type' => $type]);
            return null;
        }

        if ($scheduledAt === null && $priority !== Notification::PRIORITY_URGENT) {
            if ($this->preferenceService->isInDndMode($userId)) {
                $scheduledAt = $this->preferenceService->getNextDndEndTime($userId);
                $this->logger->info('notif.dnd_deferred', ['user_id' => $userId, 'scheduled_at' => $scheduledAt]);
            }
        }

        $notifId = null;

        try {
            if ($this->preferenceService->isInAppEnabled($userId, $type)) {
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
            }
        } catch (\Throwable $e) {
            $this->logger->error('notif.in_app_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        if ($scheduledAt === null && $this->preferenceService->isPushEnabled($userId, $type)) {
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

    private function checkRateLimit(int $userId): bool
    {
        $key = "notif_rl_user_{$userId}";
        return $this->rateLimiter->attempt($key, self::RATE_MAX_PER_USER_PER_HOUR, self::RATE_WINDOW_MINUTES);
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
        return $this->sendBulkToUsers($users, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt);
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
        $users = $this->getUsersBySegment($segment, $filters);
        $result = $this->sendBulkToUsers($users, $type, $title, $message, $data, $actionUrl, $actionText, $priority, $scheduledAt);
        $this->logger->info('notif.send_to_segment', array_merge($result, ['segment' => $segment, 'type' => $type]));
        return $result;
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

    private function sendBulkToUsers(array $users, string $type, string $title, string $message, ?array $data, ?string $actionUrl, ?string $actionText, string $priority, ?string $scheduledAt): array
    {
        $sent = 0; $skipped = 0;
        foreach ($users as $u) {
            $ok = $this->send((int)$u->id, $type, $title, $message, $data, $actionUrl, $actionText, $priority, null, null, null, $scheduledAt);
            $ok ? $sent++ : $skipped++;
        }
        return ['sent' => $sent, 'skipped' => $skipped];
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

    // --- Proxy Calls to Preference Service ---

    public function getPreferences(int $userId): object
    {
        return $this->preferenceService->getPreferences($userId);
    }

    public function updatePreferences(int $userId, array $data): bool
    {
        return $this->preferenceService->updatePreferences($userId, $data);
    }

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

    // --- Proxy Calls to Analytics Service ---

    public function getAnalyticsOverview(int $days = 30): array
    {
        return $this->analyticsService->getAnalyticsOverview($days);
    }

    public function getAnalyticsByType(int $days = 30): array
    {
        return $this->analyticsService->getAnalyticsByType($days);
    }

    public function getAnalyticsDailyTrend(int $days = 30): array
    {
        return $this->analyticsService->getAnalyticsDailyTrend($days);
    }

    public function getAnalyticsSegmentStats(int $days = 30): array
    {
        return $this->analyticsService->getAnalyticsSegmentStats($days);
    }

    public function getAnalyticsFunnelStats(int $days = 30): array
    {
        return $this->analyticsService->getAnalyticsFunnelStats($days);
    }

    public function getAnalyticsFatigueReport(int $threshold = 20): array
    {
        return $this->analyticsService->getAnalyticsFatigueReport($threshold);
    }

    // --- Common/Shortcut Methods ---

    public function saveUserToken(int $userId, string $token, string $platform = 'web'): bool
    {
        return $this->fcmService->saveUserToken($userId, $token, $platform);
    }

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
        $sentCount = 0;
        
        $actionUrl = $data['action_url'] ?? null;
        $actionText = $data['action_text'] ?? null;

        foreach ($adminIds as $adminId) {
            $result = $this->send((int)$adminId, $type, $title, $message, $data, $actionUrl, $actionText, $priority);
            if ($result) $sentCount++;
        }
        return $sentCount;
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
            $prefs = $this->preferenceService->getPreferences($userId);
            if (isset($prefs->sms_notifications) && !$prefs->sms_notifications) return;
            
            $this->dispatcher->dispatch('sms', $userId, 'هشدار امنیتی', $message);
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function sendWithdrawalSms(int $userId, float $amount, string $currency): void
    {
        try {
            $prefs = $this->preferenceService->getPreferences($userId);
            if (isset($prefs->sms_notifications) && !$prefs->sms_notifications) return;
            
            $this->dispatcher->dispatch('sms', $userId, 'برداشت تأیید شد', "برداشت {$amount} {$currency} تأیید شد");
        } catch (\Throwable $e) {
            $this->logger->warning('notif.sms_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
