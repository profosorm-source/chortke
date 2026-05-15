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
    private \Core\RateLimiter $rateLimiter;

    public function __construct(
        AdvancedSearchService $searchService,
        \Core\RateLimiter $rateLimiter
    )
    {
        parent::__construct();
        $this->searchService = $searchService;
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * جستجوی ادمین - پاسخ JSON
     */
    public function adminSearch(): void
    {
        $query = trim($this->request->get('q') ?? '');

        // Rate Limit برای ادمین (مثلاً ۵۰ جستجو در دقیقه)
        $rateKey = 'admin_search:' . user_id();
        if (!$this->rateLimiter->attempt($rateKey, 50, 1)) {
            $this->response->json(['success' => false, 'message' => 'Too many requests'], 429);
            return;
        }

        if (strlen($query) < 2) {
            $this->response->json(['success' => true, 'query' => htmlspecialchars($query), 'results' => []]);
            return;
        }

        $results = $this->searchService->searchAdmin($query, 5);

        // محاسبه تعداد کل نتایج
        $total = array_sum(array_map(fn($v) => is_array($v) ? count($v) : 0, $results));
        $results['total'] = $total;

        $this->response->json([
            'success' => true,
            'query'   => htmlspecialchars($query),
            'results' => $results,
        ]);
    }

    /**
     * جستجوی کاربر - پاسخ JSON (برای sidebar AJAX و صفحه کامل)
     */
    public function userSearch(): void
    {
        $userId = (int)user_id();
        $query = trim($this->request->get('q') ?? '');

        // Rate Limit برای کاربر (۲۰ جستجو در دقیقه)
        $rateKey = 'user_search:' . ($userId ?: get_client_ip());
        if (!$this->rateLimiter->attempt($rateKey, 20, 1)) {
            $this->response->json(['success' => false, 'message' => 'Too many requests'], 429);
            return;
        }

        if (strlen($query) < 2) {
            $this->response->json(['success' => true, 'query' => htmlspecialchars($query), 'results' => []]);
            return;
        }

        $results = $this->searchService->searchUser($query, $userId, 5);
        $total   = array_sum(array_map(fn($v) => is_array($v) ? count($v) : 0, $results));
        $results['total'] = $total;

        $this->response->json([
            'success' => true,
            'query'   => htmlspecialchars($query),
            'results' => $results,
        ]);
    }

    /**
     * مسیر /search - JSON یا صفحه HTML بسته به Accept header
     */
    public function fullResults(): void
    {
        $userId = (int)user_id();
        $query = trim($this->request->get('q') ?? '');

        // اگر AJAX / JSON بخواند
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            $this->userSearch();
            return;
        }

        // Rate Limit برای صفحه کامل (۳۰ بار در دقیقه)
        $rateKey = 'full_search:' . ($userId ?: get_client_ip());
        if (!$this->rateLimiter->attempt($rateKey, 30, 1)) {
            $this->session->setFlash('error', 'تعداد درخواست‌های شما بیش از حد مجاز است.');
            $this->response->redirect(url('/'));
            return;
        }

        $results = strlen($query) >= 2
            ? $this->searchService->searchUser($query, $userId, 20)
            : [];

        view('user.search.results', [
            'title'   => 'نتایج جستجو',
            'query'   => htmlspecialchars($query),
            'results' => $results,
        ]);
    }
}
