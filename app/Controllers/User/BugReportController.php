<?php

namespace App\Controllers\User;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Services\BugReportService;
use App\Services\UploadService;
use App\Controllers\User\BaseUserController;

class BugReportController extends BaseUserController
{
    private \App\Services\BugReportService $bugReportService;
    private UploadService $uploadService;

    public function __construct(
        \App\Services\BugReportService $bugReportService,
        UploadService $uploadService
    )
    {
        parent::__construct();
        $this->bugReportService = $bugReportService;
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

        $service = $this->bugReportService;
        $result = $service->submitReport(user_id(), $data);

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

        $reports = $this->bugReportService->getByUser(user_id(), $perPage, $offset);

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

        $service = $this->bugReportService;
        $report  = $service->find($id);
        if (!$report || $report->user_id !== user_id()) {
                        $this->session->setFlash('error', 'گزارش یافت نشد');
            return redirect(url('/bug-reports'));
        }

        $comments = $service->getComments($id, false); // بدون internal

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

        $service = $this->bugReportService;
        $result = $service->addComment($id, user_id(), 'user', $comment);

        $this->response->json($result);
    }
}