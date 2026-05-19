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

    public function __construct(MessageModerationService $moderationService)
    {
        parent::__construct();
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

        // دریافت فقط پیام‌های مرتبط با همان مکالمه گزارش‌شده جهت حفظ حریم خصوصی (به جای کل تاریخچه کاربر)
        $user_messages = $this->moderationService->getReportedMessageThread(
            (int)$report['sender_id'],
            (int)$report['recipient_id'],
            5
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

        $ok = $this->moderationService->dismissReport($id, (int)user_id());

        if ($ok) {
            $this->auditLog('message_report_dismissed', 'message_report', $id, ['status' => 'pending'], ['status' => 'dismissed']);
        }

        $this->logger->info('Message report dismissed', [
            'report_id' => $id,
            'admin_id' => user_id()
        ]);

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

        // M05: Redaction of sensitive PII in administrative views
        foreach ($blocked as &$user) {
            if (!empty($user['blocked_email'])) {
                $email = $user['blocked_email'];
                $parts = explode('@', $email);
                if (count($parts) === 2) {
                    $user['blocked_email'] = substr($parts[0], 0, 2) . '***@' . $parts[1];
                }
            }
        }

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
