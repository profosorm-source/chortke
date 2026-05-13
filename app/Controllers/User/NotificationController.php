<?php

namespace App\Controllers\User;

use App\Controllers\User\BaseUserController;
use App\Services\Notification\NotificationService;

class NotificationController extends BaseUserController
{
    private NotificationService $notificationService;

    public function __construct(
        NotificationService $notificationService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        \App\Services\Auth\AuthService $authService,
        \App\Services\User\UserService $userService,
        \App\Services\CaptchaService $captchaService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->notificationService = $notificationService;
    }

    // =========================================================================
    // صفحات
    // =========================================================================

    /**
     * لیست نوتیفیکیشن‌ها
     */
    public function index(): void
    {
        $this->requireAuth();

        $userId = $this->userId();
        $page   = max(1, (int)($this->request->input('page') ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $notifications = $this->notificationService->getUserNotifications($userId, false, $limit, $offset);
        $totalCount    = $this->notificationService->countUserNotifications($userId);
        $unreadCount   = $this->notificationService->getUnreadCount($userId);

        $this->view('user/notifications/index', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'total_count'   => $totalCount,
            'current_page'  => $page,
            'per_page'      => $limit,
            'total_pages'   => (int)ceil($totalCount / $limit),
        ]);
    }

    /**
     * صفحه تنظیمات نوتیفیکیشن
     */
    public function preferences(): void
    {
        $this->requireAuth();

        $userId = $this->userId();
        $prefs  = $this->notificationService->getPreferences($userId);

        $this->view('user/notifications/preferences', [
            'preferences' => $prefs,
        ]);
    }

    // =========================================================================
    // Ajax — Long Polling
    // =========================================================================

    /**
     * Long Polling — request باز می‌ماند تا نوتیف جدید بیاید یا timeout
     *
     * Client باید:
     *  1. GET /notifications/poll?last_id=<آخرین ID دیده‌شده>
     *  2. بعد از response → ۱–۲ ثانیه صبر → دوباره connect
     */
    public function poll(): void
    {
        $this->requireAuth();

        $userId   = $this->userId();
        $lastId   = (int)($this->request->input('last_id') ?? 0);
        $timeout  = 30;
        $interval = 2;
        $waited   = 0;

        set_time_limit($timeout + 10);
        ignore_user_abort(false);

        $new = $this->notificationService->getNewNotifications($userId, $lastId);
        if (!empty($new['notifications'])) {
            $this->response->json($new);
        }

        while ($waited < $timeout) {
            sleep($interval);
            $waited += $interval;

            if (connection_aborted()) {
                exit;
            }

            $new = $this->notificationService->getNewNotifications($userId, $lastId);
            if (!empty($new['notifications'])) {
                $this->response->json($new);
            }
        }

        $this->response->json([
            'success'       => true,
            'notifications' => [],
            'unread_count'  => $this->notificationService->getUnreadCount($userId),
            'timeout'       => true,
        ]);
    }

    /**
     * دریافت نوتیفیکیشن‌ها (Ajax — بدون long poll)
     */
    public function get(): void
    {
        $this->requireAuth();

        $userId     = $this->userId();
        $onlyUnread = $this->request->input('unread') === 'true';
        $limit      = max(1, min(50, (int)($this->request->input('limit') ?? 20)));

        $notifications = $this->notificationService->getUserNotifications($userId, $onlyUnread, $limit);
        $unreadCount   = $this->notificationService->getUnreadCount($userId);

        $this->response->json([
            'success'       => true,
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * تعداد خوانده‌نشده (برای badge)
     */
    public function unreadCount(): void
    {
        $this->requireAuth();

        $userId = $this->userId();
        $count  = $this->notificationService->getUnreadCount($userId);

        $this->response->json([
            'success' => true,
            'count'   => $count,
        ]);
    }

    // =========================================================================
    // Ajax — Actions
    // =========================================================================

    /**
     * علامت‌گذاری به عنوان خوانده‌شده + پاک‌کردن cache
     */
    public function markAsRead(): void
    {
        $this->requireAuth();

        $notificationId = (int)$this->request->input('notification_id');
        $userId         = $this->userId();

        $result = $this->notificationService->markAsRead($notificationId, $userId);

        $this->response->json([
            'success'      => $result,
            'unread_count' => $this->notificationService->getUnreadCount($userId),
            'message'      => $result ? 'علامت‌گذاری شد' : 'خطا در علامت‌گذاری',
        ]);
    }

    /**
     * علامت خواندن همه
     */
    public function markAllAsRead(): void
    {
        $this->requireAuth();

        $userId = $this->userId();

        $count = $this->notificationService->markAllAsReadCount($userId);

        $this->response->json([
            'success'      => true,
            'count'        => $count,
            'unread_count' => 0,
            'message'      => $count > 0 ? "{$count} نوتیفیکیشن خوانده شد" : 'هیچ نوتیفیکیشنی برای خواندن وجود نداشت',
        ]);
    }

    /**
     * ثبت کلیک (analytics) + redirect
     */
    public function click(): void
    {
        $this->requireAuth();

        $notifId = (int)$this->request->input('notification_id');
        $userId  = $this->userId();

        $notif = $this->notificationService->findForUser($notifId, $userId);

        if ($notif) {
            $this->notificationService->recordClick($notifId, $userId);

            if (!$notif->is_read) {
                $this->notificationService->markAsRead($notifId, $userId);
            }

            if (!empty($notif->action_url)) {
                $this->response->redirect($notif->action_url);
            }
        }

        $this->response->redirect(url('/notifications'));
    }

    /**
     * آرشیو کردن
     */
    public function archive(): void
    {
        $this->requireAuth();

        $notificationId = (int)$this->request->input('notification_id');
        $userId         = $this->userId();

        $result = $this->notificationService->archive($notificationId, $userId);

        $this->response->json([
            'success' => $result,
            'message' => $result ? 'آرشیو شد' : 'خطا در آرشیو',
        ]);
    }

    /**
     * حذف منطقی (soft delete)
     */
    public function delete(): void
    {
        $this->requireAuth();

        $notificationId = (int)$this->request->input('notification_id');
        $userId         = $this->userId();

        $result = $this->notificationService->softDelete($notificationId, $userId);

        $this->response->json([
            'success' => $result,
            'message' => $result ? 'حذف شد' : 'خطا در حذف',
        ]);
    }

    /**
     * ذخیره FCM token (از service worker مرورگر)
     */
    public function saveFcmToken(): void
    {
        $this->requireAuth();

        $token    = $this->request->input('token');
        $platform = $this->request->input('platform') ?? 'web';
        $userId   = $this->userId();

        if (empty($token)) {
            $this->response->json(['success' => false, 'message' => 'token الزامی است'], 400);
        }

        $this->notificationService->saveUserToken($userId, $token, $platform);
        $this->response->json(['success' => true]);
    }

    /**
     * ذخیره تنظیمات
     */
    public function updatePreferences(): void
    {
        $this->requireAuth();

        $userId = $this->userId();
        $data   = $this->request->all();

        $result = $this->notificationService->updatePreferences($userId, $data);

        $this->response->json([
            'success' => $result,
            'message' => $result ? 'تنظیمات ذخیره شد' : 'خطا در ذخیره تنظیمات',
        ]);
    }

    // =========================================================================
    // Internal
    // =========================================================================

    private function getNewNotifications(int $userId, int $lastId): array
    {
        return $this->notificationService->getNewNotifications($userId, $lastId);
    }
}
