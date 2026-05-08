<?php

namespace App\Controllers\Admin;

use App\Services\EmailQueueService;
use App\Services\EmailService;
use App\Models\EmailQueue;

class EmailQueueController extends BaseAdminController
{
    private EmailQueue $model;
    private EmailService $emailService;
    private EmailQueueService $emailQueueService;

    public function __construct(
        EmailQueue       $model,
        EmailService     $emailService,
        EmailQueueService $emailQueueService
    ) {
        parent::__construct();
        $this->model = $model;
        $this->emailService = $emailService;
        $this->emailQueueService = $emailQueueService;
    }

    public function index(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $perPage = 30;
        $status  = $this->request->get('status');
        $search  = $this->request->get('search');

        $data = $this->emailQueueService->getEmailsForAdmin(
            $page,
            $perPage,
            $status,
            $search
        );

        view('admin/email-queue/index', [
            'title'      => 'صف ایمیل',
            'emails'     => $data['emails'],
            'stats'      => $data['stats'],
            'total'      => $data['total'],
            'page'       => $data['page'],
            'totalPages' => $data['totalPages'],
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