<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Controller;
use App\Services\MessageModerationService;

/**
 * MessageModerationController
 * مدیریت و مدرسیون پیام‌های کاربران
 */
class MessageModerationController extends BaseAdminController
{
    protected MessageModerationService $moderationService;

    public function __construct(MessageModerationService $moderationService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->moderationService = $moderationService;
    }

    /**
     * لیست گزارش‌های پیام
     * @return void
     */
    public function reports(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        if (!$this->policyService->authorizeById('messages.moderate', user_id())) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            $this->response->redirect(url('admin/dashboard'));
            return;
        }

        $status = $this->request->input('status', 'pending');
        if (!in_array($status, ['pending', 'approved', 'dismissed'], true)) {
            $status = 'pending';
        }
        $page   = (int) $this->request->input('page', 1);
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        // Use service to get reports
        $result = $this->moderationService->getReports($status, $limit, $offset);
        $reports = $result['reports'] ?? [];
        $total = $result['total'] ?? 0;

        $this->view('admin/messages/reports', [
            'reports'      => $reports,
            'status'       => $status,
            'page'         => $page,
            'total'        => $total,
            'per_page'     => $limit,
            'total_pages'  => ceil($total / max(1, $limit))
        ]);
    }

    /**
     * نمایش جزئیات گزارش
     * @return void
     */
    public function show(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        if (!$this->policyService->authorizeById('messages.moderate', user_id())) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            $this->response->redirect(url('admin/dashboard'));
            return;
        }

        $id = (int) $this->request->param('id');

        $report = $this->moderationService->getReportDetail($id);

        if (!$report) {
            $this->response->json(['error' => 'گزارش یافت نشد'], 404);
            return;
        }

        // 🛡️ HIGH-05: کنترل دقیق limit برای مشاهده تاریخچه پیام به منظور جلوگیری از نشت اطلاعات (حداکثر ۱۰ پیام)
        $limit = min(max((int)$this->request->input('limit', 5), 5), 10);
        $user_messages = $this->moderationService->getReportedMessageThread(
            (int)$report['sender_id'],
            (int)$report['recipient_id'],
            $limit
        );

        $this->view('admin/messages/report-detail', [
            'report'          => $report,
            'user_messages'   => $user_messages
        ]);
    }

    /**
     * تایید گزارش و اقدام
     * @return void
     */
    public function approve(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        if (!$this->policyService->authorizeById('messages.moderate', user_id())) {
            $this->response->json(['error' => 'دسترسی غیرمجاز برای تعدیل پیام‌ها.'], 403);
            return;
        }

        // CORE-036: CSRF Protection
        $this->validateCsrf();

        if (!$this->request->isPost()) {
            $this->response->json(['error' => 'Method not allowed'], 405);
            return;
        }

        $id     = (int) $this->request->input('report_id');
        
        // 🛡️ CRITICAL-08: Validate existence of the report before proceeding
        if ($id <= 0 || !$this->moderationService->getReportDetail($id)) {
            $this->response->json(['error' => 'گزارش یافت نشد'], 404);
            return;
        }

        $action = $this->request->input('action', 'warn');

        $allowedActions = ['warn', 'delete', 'ban'];
        if (!in_array($action, $allowedActions, true)) {
            $this->response->json(['error' => 'اقدام نامعتبر است'], 422);
            return;
        }

        $result = $this->moderationService->approveReport($id, $action, (int)user_id());

        if ($result['success']) {
            $this->auditLog('message_report_approved', 'message_report', $id, null, [
                'action' => $action
            ]);
            $this->response->json(['success' => true, 'message' => $result['message']]);
        } else {
            $this->response->json(['error' => $result['message']], 500);
        }
    }

    /**
     * رد کردن گزارش
     * @return void
     */
    public function dismiss(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        if (!$this->policyService->authorizeById('messages.moderate', user_id())) {
            $this->response->json(['error' => 'دسترسی غیرمجاز برای تعدیل پیام‌ها.'], 403);
            return;
        }

        // CORE-036: CSRF Protection
        $this->validateCsrf();

        if (!$this->request->isPost()) {
            $this->response->json(['error' => 'Method not allowed'], 405);
            return;
        }

        $id = (int) $this->request->input('report_id');
        
        // 🛡️ CRITICAL-08: Validate existence of the report before proceeding
        if ($id <= 0 || !$this->moderationService->getReportDetail($id)) {
            $this->response->json(['error' => 'گزارش یافت نشد'], 404);
            return;
        }

        $ok = $this->moderationService->dismissReport($id, (int)user_id());

        if ($ok) {
            $this->auditLog('message_report_dismissed', 'message_report', $id, ['status' => 'pending'], ['status' => 'dismissed']);
            // 🛡️ MEDIUM-07: Log success inside the success condition
            $this->logger->info('Message report dismissed', [
                'report_id' => $id,
                'admin_id' => user_id()
            ]);
        }

        $this->response->json(['success' => $ok, 'message' => $ok ? 'گزارش رد شد' : 'خطا در رد گزارش']);
    }


    /**
     * لیست کاربران مسدود
     * @return void
     */
    public function blockedUsers(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        if (!$this->policyService->authorizeById('messages.moderate', user_id())) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            $this->response->redirect(url('admin/dashboard'));
            return;
        }

        $page   = (int) $this->request->input('page', 1);
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $blocked = $this->moderationService->getBlockedUsers($limit, $offset);
        $total = $this->moderationService->getBlockedUsersCount();

        $this->view('admin/messages/blocked-users', [
            'blocked'      => $blocked,
            'page'         => $page,
            'total'        => $total,
            'per_page'     => $limit,
            'total_pages'  => ceil($total / max(1, $limit))
        ]);
    }

    /**
     * آمار پیام‌های سیستم
     * @return void
     */
    public function stats(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        if (!$this->policyService->authorizeById('messages.moderate', user_id())) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            $this->response->redirect(url('admin/dashboard'));
            return;
        }

        $result = $this->moderationService->getStats();
        $stats = $result['stats'] ?? [];
        $top_reporters = $result['top_reporters'] ?? [];

        $this->view('admin/messages/stats', [
            'stats'        => $stats,
            'top_reporters' => $top_reporters
        ]);
    }
}
