<?php

namespace App\Controllers\Admin;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Services\BugReportService;
use App\Services\UploadService;
use App\Controllers\Admin\BaseAdminController;

class BugReportController extends BaseAdminController
{
    private \App\Services\BugReportService $bugReportService;
    private BugReport $bugReportModel;
    private BugReportComment $commentModel;
    private BugReportService $service;
    private UploadService $uploadService;

   public function __construct(
    \App\Models\BugReport $bugReportModel,
    \App\Models\BugReportComment $commentModel,
    \App\Services\BugReportService $bugReportService,
    UploadService $uploadService
) {
    parent::__construct();

    $this->bugReportModel = $bugReportModel;
    $this->commentModel = $commentModel;
    $this->bugReportService = $bugReportService;
    $this->service = $bugReportService;
    $this->uploadService = $uploadService;
}

    /**
     * لیست گزارش‌ها
     */
    public function index()
    {
                $page = (int)($this->request->get('page') ?: 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = [];
        foreach (['status', 'priority', 'category', 'search', 'is_suspicious', 'date_from', 'date_to'] as $key) {
            $val = $this->request->get($key);
            if ($val !== null && $val !== '') {
                $filters[$key] = $val;
            }
        }

        $reports = $this->bugReportModel->allFiltered($filters, $perPage, $offset);
        $total = $this->bugReportModel->count($filters);
        $totalPages = (int)\ceil($total / $perPage);
        $stats = $this->bugReportModel->getStats();
        $categoryStats = $this->bugReportModel->getStatsByCategory();

        return view('admin.bug-reports.index', [
            'reports' => $reports,
            'stats' => $stats,
            'categoryStats' => $categoryStats,
            'filters' => $filters,
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
                $id = (int)$this->request->param('id');

        $report = $this->bugReportModel->find($id);
        if (!$report) {
                        $this->session->setFlash('error', 'گزارش یافت نشد');
            return redirect(url('/admin/bug-reports'));
        }

        $comments = $this->commentModel->getByReport($id, true); // شامل internal

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
                        $id = (int)$this->request->param('id');

        $rawData = \file_get_contents('php://input');
        $data = \json_decode($rawData, true) ?? [];

        $status = $data['status'] ?? '';
        $note = $data['note'] ?? null;

        $result = $this->service->updateStatus($id, $status, user_id(), $note);

        $this->response->json($result);
    }

    /**
     * تغییر اولویت (AJAX)
     */
    public function updatePriority(): void
    {
                        $id = (int)$this->request->param('id');

        $rawData = \file_get_contents('php://input');
        $data = \json_decode($rawData, true) ?? [];

        $priority = $data['priority'] ?? '';

        $result = $this->service->updatePriority($id, $priority, user_id());

        $this->response->json($result);
    }

    /**
     * افزودن کامنت ادمین (AJAX)
     */
    public function addComment(): void
    {
        $id = (int)$this->request->param('id');

        $rawData = \file_get_contents('php://input');
        $data = \json_decode($rawData, true);
        if (!\is_array($data)) {
            $data = [];
        }

        // اگر درخواست multipart/form-data باشد، JSON خالی می‌شود؛ از $_POST fallback بگیر
        if (empty($data) && !empty($_POST)) {
            $data = $_POST;
        }

        $comment = trim((string)($data['comment'] ?? ''));
        $isInternal = filter_var($data['is_internal'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($comment === '') {
            $this->response->json([
                'success' => false,
                'message' => 'متن کامنت الزامی است',
            ], 422);
            return;
        }

        $attachment = null;

        if (isset($_FILES['attachment']) && is_array($_FILES['attachment'])) {
            $attachment = $_FILES['attachment'];
        }

        $result = $this->service->addComment($id, user_id(), 'admin', $comment, $isInternal, $attachment);
        $this->response->json($result);
    }

    /**
     * تغییر وضعیت مشکوک (AJAX)
     */
    public function toggleSuspicious(): void
    {
                        $id = (int)$this->request->param('id');

        $result = $this->service->toggleSuspicious($id);

        $this->response->json($result);
    }

    /**
     * حذف نرم (AJAX)
     */
    public function delete(): void
    {
                        $id = (int)$this->request->param('id');

        $result = $this->service->deleteReport($id);

        $this->response->json($result);
    }
}
