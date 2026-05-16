<?php

namespace App\Controllers\Admin;

use Core\Response;
use App\Services\ApiTokenService;
use App\Services\AdvancedSearchService;

class ApiTokenAdminController extends BaseAdminController
{
    private \App\Services\AuditTrail $auditTrail;

    public function __construct(
        ApiTokenService $apiTokenService,
        AdvancedSearchService $searchService,
        \App\Services\AuditTrail $auditTrail
    ) {
        parent::__construct();
        $this->apiTokenService = $apiTokenService;
        $this->searchService = $searchService;
        $this->auditTrail = $auditTrail;
    }

    public function index(): void
    {
        $page = max(1, (int)($this->request->get('page') ?? 1));
        $search = trim($this->request->get('search') ?? '');
        $statusFilter = $this->request->get('status');
        $perPage = 30;
        $offset = ($page - 1) * $perPage;

        $filters = [];
        if (!empty($statusFilter)) {
            $filters['status'] = $statusFilter;
        }

        // استفاده از AdvancedSearchService برای جستجو
        if (!empty($search)) {
            $result = $this->searchService->searchTokens($search, $filters, $perPage, $offset);
            $tokens = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            $result = $this->apiTokenService->getTokensForAdmin($page, $perPage, $search, $statusFilter);
            $tokens = $result['tokens'];
            $total = $result['total'];
        }

        view('admin/api-tokens/index', [
            'title' => 'توکن‌های API',
            'tokens' => $tokens,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'stats' => $result['stats'] ?? [],
            'statusFilter' => $statusFilter,
            'search' => $search,
        ]);
    }

    public function revoke(): void
    {
        $this->validateCsrf();
        $id = (int)$this->request->param('id');
        $ok = $this->apiTokenService->revokeToken($id);
        
        if ($ok) {
            $this->auditTrail->record(
                'admin.api_token.revoke',
                (int)user_id(),
                ['token_id' => $id, 'action' => 'revoke', 'ip' => get_client_ip()],
                (int)user_id()
            );
        }

        $this->response->json(['success' => $ok, 'message' => $ok ? 'باطل شد' : 'یافت نشد']);
    }

    public function revokeExpired(): void
    {
        $count = $this->apiTokenService->revokeAllExpiredTokens();
        $this->response->json(['success' => true, 'count' => $count]);
    }
}