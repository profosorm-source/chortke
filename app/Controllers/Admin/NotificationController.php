<?php

namespace App\Controllers\Admin;

use App\Models\Notification;
use App\Services\Notification\NotificationService;
use App\Controllers\Admin\BaseAdminController;

class NotificationController extends BaseAdminController
{
    private Notification         $model;
    private NotificationService  $notificationService;

    public function __construct(
        Notification        $model,
        NotificationService $notificationService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->model              = $model;
        $this->notificationService = $notificationService;
    }

    // =========================================================================
    // صفحات اصلی
    // =========================================================================

    /**
     * لیست اعلان‌های ادمین
     */
    public function index(): void
    {
        $userId        = user_id();
        $notifications = $this->notificationService->latest($userId, 50);
        $unreadCount   = $this->notificationService->getUnreadCount($userId);

        view('admin/notifications/index', [
            'title'         => 'اعلان‌ها',
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    /**
     * صفحه ارسال اعلان — با segment و زمان‌بندی
     */
    public function showSend(): void
    {
        view('admin/notifications/send', [
            'title'            => 'ارسال اعلان به کاربران',
            'segments'         => $this->notificationService->getAvailableSegments(),
            'notification_types' => [
                Notification::TYPE_SYSTEM     => 'سیستمی',
                Notification::TYPE_INFO       => 'اطلاعیه',
                Notification::TYPE_MARKETING  => 'تبلیغاتی',
                Notification::TYPE_TASK       => 'تسک',
                Notification::TYPE_SECURITY   => 'امنیتی',
            ],
        ]);
    }

    /**
     * پردازش ارسال اعلان دستی
     */
    public function send(): void
    {
        $target      = trim($this->request->input('target', 'all'));
        $segment     = trim($this->request->input('segment', 'all'));
        $type        = trim($this->request->input('type', 'info'));
        $title       = trim($this->request->input('title', ''));
        $message     = trim($this->request->input('message', ''));
        $userId      = (int)$this->request->input('user_id', 0);
        $priority    = trim($this->request->input('priority', 'normal'));
        $scheduledAt = trim($this->request->input('scheduled_at', ''));
        $actionUrl   = trim($this->request->input('action_url', ''));
        $actionText  = trim($this->request->input('action_text', ''));

        if ($title === '' || $message === '') {
            $this->session->setFlash('error', 'عنوان و متن اعلان الزامی است.');
            redirect('/admin/notifications/send');
            return;
        }

        $scheduledAt = !empty($scheduledAt) ? $scheduledAt : null;
        $actionUrl   = !empty($actionUrl)   ? $actionUrl   : null;
        $actionText  = !empty($actionText)  ? $actionText  : null;

        $sent = 0;

        if ($target === 'all' || $target === 'segment') {
            $seg    = ($target === 'all') ? 'all' : $segment;
            $result = $this->notificationService->sendToSegment(
                $seg, $title, $message, $type,
                $actionUrl, $actionText, $priority, null, $scheduledAt
            );
            $sent = $result['sent'] ?? 0;

        } elseif ($target === 'user' && $userId > 0) {
            $notifId = $this->notificationService->send(
                $userId, $type, $title, $message,
                null, $actionUrl, $actionText, $priority,
                null, null, null, $scheduledAt
            );
            $sent = $notifId ? 1 : 0;

        } else {
            $this->session->setFlash('error', 'هدف ارسال نامعتبر است.');
            redirect('/admin/notifications/send');
            return;
        }

        $msg = $scheduledAt
            ? "اعلان برای {$sent} کاربر زمان‌بندی شد."
            : "اعلان با موفقیت به {$sent} کاربر ارسال شد.";

       $this->logger->activity('admin_notification_sent', $msg, user_id(), []);
        $this->session->setFlash('success', $msg);
        redirect('/admin/notifications');
    }

    // =========================================================================
    // Analytics
    // =========================================================================

    /**
     * داشبورد آمار کامل
     */
    public function stats(): void
    {
        $days      = max(7, min(90, (int)$this->request->get('days', 30)));
        $dashboard = $this->notificationService->getAnalyticsOverview($days);

        view('admin/notifications/stats', [
            'title'     => 'آمار اعلان‌ها',
            'dashboard' => $dashboard,
            'days'      => $days,
        ]);
    }

    /**
     * Ajax — داشبورد JSON
     */
    public function statsFetch(): void
    {
        $days = max(7, min(90, (int)$this->request->get('days', 30)));

        $this->response->json([
            'success'   => true,
            'dashboard' => $this->notificationService->getAnalyticsOverview($days),
        ]);
    }

    // =========================================================================
    // Template Management
    // =========================================================================

    /**
     * لیست template‌ها
     */
    public function templates(): void
    {
        view('admin/notifications/templates', [
            'title'     => 'قالب‌های نوتیفیکیشن',
            'templates' => $this->notificationService->getAllTemplatesWithVariables(),
        ]);
    }

    /**
     * ذخیره override template
     */
    public function saveTemplate(): void
    {
        $key     = trim($this->request->input('template_key', ''));
        $title   = trim($this->request->input('title', ''));
        $message = trim($this->request->input('message', ''));

        if (empty($key) || empty($title) || empty($message)) {
            $this->response->json(['success' => false, 'error' => 'فیلدهای الزامی خالی است.'], 400);
            return;
        }

        $result = $this->notificationService->saveTemplateOverride($key, $title, $message);

        if ($result) {
            $this->logger->activity('notif_template_saved', "ذخیره template: {$key}", user_id(), []);
        }

        $this->response->json(['success' => $result], $result ? 200 : 422);
    }

    /**
     * حذف override (بازگشت به default)
     */
    public function deleteTemplate(): void
    {
        $key = trim($this->request->input('template_key', ''));

        if (empty($key)) {
            $this->response->json(['success' => false, 'error' => 'کلید template الزامی است.'], 400);
            return;
        }

        $this->notificationService->deleteTemplateOverride($key);
       $this->logger->activity('notif_template_deleted', "حذف override template: {$key}", user_id(), []);

        $this->response->json(['success' => true, 'message' => 'بازگشت به template پیش‌فرض']);
    }

    // =========================================================================
    // Ajax — کنترل نوتیف ادمین
    // =========================================================================

    public function fetch(): void
    {
        $items  = $this->notificationService->latest(user_id(), 10);
        $unread = $this->notificationService->getUnreadCount(user_id());

        $this->response->json([
            'success'       => true,
            'notifications' => $items,
            'unread_count'  => $unread,
        ]);
    }

    public function unreadCount(): void
    {
        $this->response->json([
            'success' => true,
            'count'   => $this->notificationService->getUnreadCount(user_id()),
        ]);
    }

    public function markAsRead(int $id): void
    {
        $ok = $this->model->markAsRead($id, user_id());

        if ($ok) {
            $this->notificationService->invalidateUnreadCache(user_id());
        }

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'خوانده شد' : 'عملیات ناموفق بود',
        ], $ok ? 200 : 400);
    }

    public function markAllAsRead(): void
    {
        $ok = $this->model->markAllAsRead(user_id());

        if ($ok) {
            $this->notificationService->invalidateUnreadCache(user_id());
        }

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'همه خوانده شدند' : 'عملیات ناموفق بود',
        ], $ok ? 200 : 400);
    }
}
