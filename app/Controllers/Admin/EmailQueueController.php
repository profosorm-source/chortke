<?php

namespace App\Controllers\Admin;

use App\Services\RedisEmailQueueService;
use App\Services\EmailService;
use App\Models\EmailQueue;
use App\Services\Search\SearchOrchestrator;

class EmailQueueController extends BaseAdminController
{
    private EmailQueue $model;
    private EmailService $emailService;
    private RedisEmailQueueService $emailQueueService;
    private SearchOrchestrator $searchService;

    public function __construct(
        EmailQueue       $model,
        EmailService     $emailService,
        RedisEmailQueueService $emailQueueService,
        SearchOrchestrator $searchService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->model = $model;
        $this->emailService = $emailService;
        $this->emailQueueService = $emailQueueService;
        $this->searchService = $searchService;
    }

    public function index(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $perPage = 30;
        $status  = $this->request->get('status');
        $search  = trim($this->request->get('search') ?? '');
        $offset  = ($page - 1) * $perPage;

        $filters = [];
        if (!empty($status)) {
            $filters['status'] = $status;
        }

        // استفاده از SearchOrchestrator برای جستجو
        if (!empty($search)) {
            $result = $this->searchService->searchEmails($search, $filters, $perPage, $offset);
            $emails = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
            $data = [
                'emails' => $emails,
                'stats' => [],
                'total' => $total,
                'page' => $page,
                'totalPages' => ceil($total / $perPage),
            ];
        } else {
            $data = $this->emailQueueService->getEmailsForAdmin(
                $page,
                $perPage,
                $status,
                $search
            );
        }

        view('admin/email-queue/index', [
            'title'      => 'صف ایمیل',
            'emails'     => $data['emails'],
            'stats'      => $data['stats'],
            'total'      => $data['total'],
            'page'       => $data['page'],
            'totalPages' => $data['totalPages'],
            'search'     => $search,
        ]);
    }

    public function process(): void
    {
        $result = $this->emailService->processQueue(20);
        $this->response->json($result);
    }

    public function retryFailed(): void
    {
        $count = $this->emailQueueService->retryAllFailed();
        $this->response->json(['success' => true, 'count' => $count]);
    }

    public function retry(): void
    {
        $id = (int)$this->request->param('id');
        $ok = $this->emailQueueService->retryEmail($id);
        $this->response->json(['success' => $ok, 'message' => $ok ? 'آماده تلاش مجدد' : 'یافت نشد']);
    }
}