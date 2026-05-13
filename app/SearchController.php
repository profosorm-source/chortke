<?php

namespace App\Controllers;

use App\Services\AdvancedSearchService;
use App\Controllers\BaseController;

/**
 * SearchController - جستجوی جامع
 *
 * GET /admin/search?q=...   → نتایج ادمین (JSON)
 * GET /search?q=...         → نتایج کاربر (JSON یا صفحه)
 */
class SearchController extends BaseController
{
    private AdvancedSearchService $searchService;

    public function __construct(
        
        AdvancedSearchService $searchService
    )
    {
parent::__construct();
        $this->searchService = $searchService;
    }

    /**
     * جستجوی ادمین - پاسخ JSON
     */
    public function adminSearch(): void
    {
                        $query    = trim($this->request->get('q') ?? '');

        if (strlen($query) < 2) {
            $this->response->json(['success' => true, 'query' => $query, 'results' => []]);
            return;
        }

        $results = $this->searchService->searchAdmin($query, 5);

        // محاسبه تعداد کل نتایج
        $total = array_sum(array_map(fn($v) => is_array($v) ? count($v) : 0, $results));
        $results['total'] = $total;

        $this->response->json([
            'success' => true,
            'query'   => $query,
            'results' => $results,
        ]);
    }

    /**
     * جستجوی کاربر - پاسخ JSON (برای sidebar AJAX و صفحه کامل)
     */
    public function userSearch(): void
    {
                        $userId   = (int)user_id();
        $query    = trim($this->request->get('q') ?? '');

        if (strlen($query) < 2) {
            $this->response->json(['success' => true, 'results' => []]);
            return;
        }

        $results = $this->searchService->searchUser($query, $userId, 5);
        $total   = array_sum(array_map(fn($v) => is_array($v) ? count($v) : 0, $results));
        $results['total'] = $total;

        $this->response->json([
            'success' => true,
            'query'   => $query,
            'results' => $results,
        ]);
    }

    /**
     * مسیر /search - JSON یا صفحه HTML بسته به Accept header
     */
    public function fullResults(): void
    {
                $userId  = (int)user_id();
        $query   = trim($this->request->get('q') ?? '');

        // اگر AJAX / JSON بخواند
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            $this->userSearch();
            return;
        }

        $results = strlen($query) >= 2
            ? $this->searchService->searchUser($query, $userId, 20)
            : [];

        view('user.search.results', [
            'title'   => 'نتایج جستجو',
            'query'   => $query,
            'results' => $results,
        ]);
    }
}
