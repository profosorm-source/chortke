<?php

namespace App\Controllers\Admin;

use Core\Response;
use App\Services\ApiTokenService;

class ApiTokenAdminController extends BaseAdminController
{
    private ApiTokenService $apiTokenService;

    public function __construct(ApiTokenService $apiTokenService)
    {
        parent::__construct();
        $this->apiTokenService = $apiTokenService;
    }

    public function index(): void
    {
        $page = max(1, (int)($this->request->get('page') ?? 1));
        $search = $this->request->get('search');
        $statusFilter = $this->request->get('status');

        $result = $this->apiTokenService->getTokensForAdmin(
            $page,
            30,
            $search,
            $statusFilter
        );

        view('admin/api-tokens/index', [
            'title' => 'توکن‌های API',
            'tokens' => $result['tokens'],
            'total' => $result['total'],
            'page' => $result['page'],
            'perPage' => $result['perPage'],
            'stats' => $result['stats'],
            'statusFilter' => $statusFilter,
            'search' => $search,
        ]);
    }

    public function revoke(): void
    {
        $id = (int)$this->request->param('id');
        $ok = $this->apiTokenService->revokeToken($id);
        $this->response->json(['success' => $ok, 'message' => $ok ? 'باطل شد' : 'یافت نشد']);
    }

    public function revokeExpired(): void
    {
        $count = $this->apiTokenService->revokeAllExpiredTokens();
        $this->response->json(['success' => true, 'count' => $count]);
    }
}