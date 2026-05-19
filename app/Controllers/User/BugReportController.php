<?php

namespace App\Controllers\User;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Services\TicketService;
use App\Services\UploadService;
use App\Controllers\User\BaseUserController;

class BugReportController extends BaseUserController
{
    private TicketService $ticketService;
    private UploadService $uploadService;

    public function __construct(
        TicketService $ticketService,
        UploadService $uploadService
    )
    {
        parent::__construct();
        $this->ticketService = $ticketService;
        $this->uploadService = $uploadService;
    }

    public function store(): void
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $userId = user_id();
        if (!$userId) {
            $this->response->json(['success' => false, 'message' => 'لطفاً وارد حساب خود شوید'], 401);
            return;
        }

        // H-08: Spam Flood Protection
        try {
            rate_limit('bug_report', 'store', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->response->json(['success' => false, 'message' => $e->getMessage()], 429);
                return;
            }
        }

        $screenRes = $this->request->post('screen_resolution') ?? '';
        if ($screenRes && !preg_match('/^\d{1,5}x\d{1,5}$/', $screenRes)) {
            $screenRes = '';
        }

        $fingerprint = $this->request->post('device_fingerprint') ?? '';
        if (strlen($fingerprint) > 128) {
            $fingerprint = substr($fingerprint, 0, 128);
        }

        // C-05: Input Sanitization (XSS Protection)
        $data = [
            'page_url'           => filter_var($this->request->post('page_url'), FILTER_SANITIZE_URL),
            'page_title'         => htmlspecialchars($this->request->post('page_title') ?? '', ENT_QUOTES, 'UTF-8'),
            'category'           => htmlspecialchars($this->request->post('category') ?: 'other', ENT_QUOTES, 'UTF-8'),
            'description'        => htmlspecialchars($this->request->post('description') ?? '', ENT_QUOTES, 'UTF-8'),
            'screen_resolution'  => htmlspecialchars($screenRes, ENT_QUOTES, 'UTF-8'),
            'device_fingerprint' => htmlspecialchars($fingerprint, ENT_QUOTES, 'UTF-8'),
            'user_agent'         => substr($this->request->header('User-Agent') ?? '', 0, 512),
            'ip_address'         => $this->request->ip(),
        ];

        if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = $this->uploadService->upload(
                $_FILES['screenshot'],
                'bug-reports',
                ['jpg', 'png', 'jpeg'],
                5 * 1024 * 1024
            );

            if ($uploadResult['success']) {
                $data['screenshot'] = $uploadResult['path'];
            }
        }

        $result = $this->ticketService->submitBugReport($userId, $data, $this->uploadService);

        if ($result['success']) {
            $result['message'] = 'گزارش شما با موفقیت در سیستم ثبت شد.';
        }

        $this->response->json($result);
    }

    /**
     * لیست گزارش‌های کاربر
     */
    public function index()
    {
        if (!auth()) {
            return redirect(url('/login'));
        }

                $page = (int)($this->request->get('page') ?: 1);
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $reports = $this->ticketService->getBugReports(user_id(), $perPage, $offset);

        return view('user.bug-reports.index', [
            'reports' => $reports,
            'page' => $page,
        ]);
    }

    /**
     * جزئیات گزارش
     */
    public function show()
    {
        $id = (int)$this->request->param('id');

        $service = $this->ticketService;
        $report  = $service->findBugReport($id);
        if (!$report || (int)$report->user_id !== user_id()) {
            $this->session->setFlash('error', 'گزارش یافت نشد');
            return redirect(url('/bug-reports'));
        }

        $comments = $service->getBugReportComments($id);

        return view('user.bug-reports.show', [
            'report' => $report,
            'comments' => $comments,
        ]);
    }

    public function addComment(): void
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $userId = user_id();
        $id = (int)$this->request->param('id');

        // C-03: Ownership Verification (IDOR Protection)
        $report = $this->ticketService->findBugReport($id);
        if (!$report || (int)$report->user_id !== $userId) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد یا دسترسی غیرمجاز است'], 403);
            return;
        }

        $data = $this->request->json() ?? [];
        $comment = trim($data['comment'] ?? '');

        if (empty($comment)) {
            $this->response->json(['success' => false, 'message' => 'متن نظر نمی‌تواند خالی باشد']);
            return;
        }

        $result = $this->ticketService->reply($id, $userId, $comment, false);

        $this->response->json($result);
    }
}