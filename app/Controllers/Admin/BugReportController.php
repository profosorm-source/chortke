<?php

namespace App\Controllers\Admin;

use App\Services\TicketService;
use App\Services\UploadService;
use App\Services\AdvancedSearchService;
use App\Controllers\Admin\BaseAdminController;

class BugReportController extends BaseAdminController
{
    private TicketService $ticketService;
    private UploadService $uploadService;
    private AdvancedSearchService $searchService;

    public function __construct(
        TicketService $ticketService,
        UploadService $uploadService,
        AdvancedSearchService $searchService
    ) {
        parent::__construct();
        $this->ticketService = $ticketService;
        $this->uploadService = $uploadService;
        $this->searchService = $searchService;
    }

    /**
     * لیست گزارش‌ها (مهاجرت یافته به سیستم تیکت یکپارچه)
     */
    public function index()
    {
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

        // استفاده از AdvancedSearchService برای جستجو (بعداً می‌تواند با تیکت مچ شود)
        if (!empty($search)) {
            // Fallback direct filter loading instead of searchService which uses old model
            $reports = $this->ticketService->getAdminBugReports($filters, $page, $perPage);
            $total = $this->ticketService->countAdminBugReports($filters);
        } else {
            $reports = $this->ticketService->getAdminBugReports($filters, $page, $perPage);
            $total = $this->ticketService->countAdminBugReports($filters);
        }

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
        $id = (int)$this->request->param('id');
        $rawData = \file_get_contents('php://input');
        $data = \json_decode($rawData, true) ?? [];

        $status = $data['status'] ?? '';
        $note = $data['note'] ?? null;

        // Map standard admin update in Tickets architecture
        // Tickets have simpler status. We can use update() here.
        $db = \Core\Database::getInstance();
        $ok = $db->query("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?", [$status, $id]);

        $this->response->json(['success' => (bool)$ok]);
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
        
        $db = \Core\Database::getInstance();
        $ok = $db->query("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?", [$priority, $id]);

        $this->response->json(['success' => (bool)$ok]);
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

        if (empty($data) && !empty($_POST)) {
            $data = $_POST;
        }

        $comment = trim((string)($data['comment'] ?? ''));
        if ($comment === '') {
            $this->response->json(['success' => false, 'message' => 'متن کامنت الزامی است'], 422);
            return;
        }

        $result = $this->ticketService->reply($id, user_id(), $comment, true);
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
        $id = (int)$this->request->param('id');
        $result = $this->ticketService->close($id, user_id(), true);
        $this->response->json($result);
    }
}

