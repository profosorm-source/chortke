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

    /**
     * ثبت گزارش باگ (AJAX)
     */
    public function store(): void
    {
                
        if (!auth()) {
    $this->response->json(['success' => false, 'message' => 'لطفاً وارد حساب خود شوید']);
    return;
}

        $data = [
            'page_url' => $this->request->post('page_url'),
            'page_title' => $this->request->post('page_title'),
            'category' => $this->request->post('category') ?: 'other',
            'description' => $this->request->post('description'),
            'screen_resolution' => $this->request->post('screen_resolution'),
            'device_fingerprint' => $this->request->post('device_fingerprint'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? get_user_agent(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? get_client_ip(),
        ];

        if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] !== UPLOAD_ERR_NO_FILE) {
            // استفاده از UploadService (Sprint 6)
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

        $service = $this->ticketService;
        $result = $service->submitBugReport(user_id(), $data, $this->uploadService);

        // Match client-expected response message structure
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

    /**
     * افزودن کامنت توسط کاربر (AJAX)
     */
    public function addComment(): void
    {
                        $id = (int)$this->request->param('id');

        $rawData = \file_get_contents('php://input');
        $data = \json_decode($rawData, true) ?? [];

        $comment = $data['comment'] ?? '';

        $service = $this->ticketService;
        $result = $service->reply($id, user_id(), $comment, false);

        $this->response->json($result);
    }
}