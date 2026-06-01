<?php

namespace App\Controllers\Admin;

use App\Services\TicketService;
use App\Services\UploadService;
use App\Services\Search\SearchOrchestrator;
use App\Controllers\Admin\BaseAdminController;

class BugReportController extends BaseAdminController
{
    private TicketService $ticketService;
    private UploadService $uploadService;
    private SearchOrchestrator $searchService;

    public function __construct(
        TicketService $ticketService,
        UploadService $uploadService,
        SearchOrchestrator $searchService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->ticketService = $ticketService;
        $this->uploadService = $uploadService;
        $this->searchService = $searchService;
    }

    /**
     * لیست گزارش‌ها (مهاجرت یافته به سیستم تیکت یکپارچه)
     */
    public function index()
    {
        // 🛡️ CRIT-02: بررسی دسترسی مشاهده گزارش‌های باگ
        if (!$this->policyService->authorizeById('bug_reports.view', user_id())) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect(url('/admin'));
        }

        $page = (int)($this->request->get('page') ?: 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $search = trim($this->request->get('search') ?? '');

        $filters = [];
        foreach (['status', 'priority', 'category', 'date_from', 'date_to'] as $key) {
            $val = $this->request->get($key);
            if ($val !== null && $val !== '') {
                $filters[$key] = $val;
            }
        }

        if (!empty($search)) {
            $filters['search'] = $search;
        }
        $reports = $this->ticketService->getAdminBugReports($filters, $page, $perPage);
        $total = $this->ticketService->countAdminBugReports($filters);

        $totalPages = (int)\ceil($total / $perPage);
        $stats = $this->ticketService->getAdminBugStats();
        $categoryStats = []; // Simplified representation

        return view('admin.bug-reports.index', [
            'reports' => $reports,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
            'filters' => $filters,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    /**
     * جزئیات گزارش
     */
    public function show()
    {
        // 🛡️ CRIT-02: بررسی دسترسی مشاهده گزارش‌های باگ
        if (!$this->policyService->authorizeById('bug_reports.view', user_id())) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect(url('/admin'));
        }

        $id = (int)$this->request->param('id');

        $report = $this->ticketService->findBugReport($id);
        if (!$report) {
            $this->session->setFlash('error', 'گزارش یافت نشد');
            return redirect(url('/admin/bug-reports'));
        }

        $comments = $this->ticketService->getBugReportComments($id);

        return view('admin.bug-reports.show', [
            'report' => $report,
            'comments' => $comments,
        ]);
    }

    /**
     * تغییر وضعیت (AJAX)
     */
    public function updateStatus(): void
    {
        $this->validateCsrf();

        // 🛡️ HIGH-11: بررسی دسترسی اختصاصی جهت تغییر وضعیت گزارش‌های باگ
        if (!$this->policyService->authorizeById('bug_reports.update_status', user_id())) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = (int)$this->request->param('id');
        $data = $this->request->json() ?? [];

        $status = $data['status'] ?? '';
        if (!in_array($status, \App\Enums\TicketStatus::all(), true)) {
            $this->response->json(['success' => false, 'message' => 'وضعیت نامعتبر است.'], 422);
            return;
        }

        // 🛡️ MED-06: تایید اصالت گزارش باگ قبل از هرگونه تغییر
        $oldReport = $this->ticketService->findBugReport($id);
        if (!$oldReport) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }
        $oldStatus = $oldReport->status;

        $ok = $this->ticketService->updateStatus($id, $status, user_id());
        if ($ok) {
            $this->auditLog('bug_report_status_changed', 'bug_report', $id, ['status' => $oldStatus], ['status' => $status]);
        }

        $this->response->json(['success' => $ok]);
    }

    /**
     * تغییر اولویت (AJAX)
     */
    public function updatePriority(): void
    {
        $this->validateCsrf();

        // 🛡️ HIGH-11: بررسی دسترسی اختصاصی جهت تغییر اولویت گزارش‌های باگ
        if (!$this->policyService->authorizeById('bug_reports.update_priority', user_id())) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = (int)$this->request->param('id');
        $data = $this->request->json() ?? [];

        $priority = $data['priority'] ?? '';
        if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            $this->response->json(['success' => false, 'message' => 'اولویت نامعتبر است.'], 422);
            return;
        }

        // 🛡️ MED-06: تایید اصالت گزارش باگ قبل از هرگونه تغییر
        $oldReport = $this->ticketService->findBugReport($id);
        if (!$oldReport) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }
        $oldPriority = $oldReport->priority;

        $ok = $this->ticketService->updatePriority($id, $priority, user_id());
        if ($ok) {
            $this->auditLog('bug_report_priority_changed', 'bug_report', $id, ['priority' => $oldPriority], ['priority' => $priority]);
        }

        $this->response->json(['success' => $ok]);
    }

    /**
     * افزودن کامنت ادمین (AJAX)
     */
    public function addComment(): void
    {
        $this->validateCsrf();

        // 🛡️ CRIT-02: بررسی دسترسی ادمین
        if (!$this->policyService->authorizeById('bug_reports.view', user_id())) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = (int)$this->request->param('id');

        // 🛡️ MED-06: تایید وجود و اصالت گزارش باگ
        $report = $this->ticketService->findBugReport($id);
        if (!$report) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }

        // 🛡️ MED-06: ممانعت از ثبت کامنت جدید و بازگشایی خودکار تیکت در صورت بسته بودن گزارش
        if ($report->status === 'closed') {
            $this->response->json(['success' => false, 'message' => 'امکان ثبت کامنت روی گزارش بسته شده وجود ندارد.'], 400);
            return;
        }

        // 🛡️ HIGH-01: Rate limiting سبک برای ادمین در پاسخ به گزارش باگ
        $adminId = user_id();
        $rateLimiter = app(\Core\RateLimiter::class);
        $rateKey = "admin_bugreport_reply:{$adminId}";
        if (!$rateLimiter->attempt($rateKey, 100, 3600)) {
            $this->logger->critical('admin_rate_limit_exceeded', ['admin_id' => $adminId]);
            $this->response->json(['success' => false, 'message' => 'تعداد پاسخ‌های شما غیرعادی است'], 429);
            return;
        }

        $data = $this->request->json() ?? [];
        if (empty($data)) {
            $data = $this->request->all();
        }

        // 🛡️ CRIT-03: ضدعفونی صریح کامنت ادمین جهت مقابله با حملات Stored XSS
        $comment = htmlspecialchars(trim((string)($data['comment'] ?? '')), ENT_QUOTES, 'UTF-8', false);
        if ($comment === '') {
            $this->response->json(['success' => false, 'message' => 'متن کامنت الزامی است'], 422);
            return;
        }

        $result = $this->ticketService->reply($id, user_id(), $comment, true);
        if ($result['success'] ?? false) {
            $this->auditLog('bug_report_admin_comment', 'bug_report', $id, null, [
                'comment_length' => mb_strlen($comment),
                'has_sanitized' => true
            ]);
        }

        $this->response->json($result);
    }

    /**
     * تغییر وضعیت مشکوک (Deprecated in unified model)
     */
    public function toggleSuspicious(): void
    {
        $this->response->json(['success' => true, 'message' => 'ویژگی در مدل یکپارچه لغو شده است']);
    }

    /**
     * بستن تیکت (به جای حذف نرم)
     */
    public function delete(): void
    {
        $this->validateCsrf();

        // 🛡️ CRIT-02: بررسی دسترسی ادمین
        if (!$this->policyService->authorizeById('bug_reports.view', user_id())) {
            $this->response->json(['success' => false, 'message' => 'دسترسی غیرمجاز.'], 403);
            return;
        }

        $id = (int)$this->request->param('id');

        // 🛡️ MED-06: تایید اصالت گزارش باگ
        $oldReport = $this->ticketService->findBugReport($id);
        if (!$oldReport) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }
        $oldStatus = $oldReport->status;

        $result = $this->ticketService->close($id, user_id(), true);
        if ($result['success'] ?? false) {
            $this->auditLog('bug_report_closed', 'bug_report', $id, ['status' => $oldStatus], ['status' => 'closed']);
        }

        $this->response->json($result);
    }
}

