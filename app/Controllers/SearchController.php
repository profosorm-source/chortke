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

        // Rate Limit چندلایه برای ادمین (IP + User ID + Global)
        $userId = user_id();
        $ip = get_client_ip();
        $fingerprint = function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : md5($ip);

        $limits = [
            'admin_search_user:' . $userId        => [50, 1],
            'admin_search_ip:' . $ip              => [100, 1],
            'admin_search_fingerprint:' . $fingerprint => [60, 1],
        ];

        foreach ($limits as $key => $conf) {
            if (!$this->rateLimiter->attempt($key, $conf[0], $conf[1])) {
                $this->response->json(['success' => false, 'message' => 'Too many requests'], 429);
                return;
            }
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
        $ip = get_client_ip();
        $fingerprint = function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : md5($ip);

        // Rate Limit چندلایه برای کاربر (IP + User ID + Fingerprint)
        $limits = [
            'user_search_ip:' . $ip => [30, 1],
            'user_search_fingerprint:' . $fingerprint => [20, 1],
        ];

        if ($userId > 0) {
            $limits['user_search_user:' . $userId] = [20, 1];
        }

        foreach ($limits as $key => $conf) {
            if (!$this->rateLimiter->attempt($key, $conf[0], $conf[1])) {
                $this->response->json(['success' => false, 'message' => 'Too many requests'], 429);
                return;
            }
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
     * مسیر /search - JSON یا صفحه HTML صفحه کامل
     */
    public function fullResults(): void
    {
        $userId = (int)user_id();
        $query = trim($this->request->get('q') ?? '');
        $ip = get_client_ip();
        $fingerprint = function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : md5($ip);

        // اگر AJAX / JSON بخواند
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            $this->userSearch();
            return;
        }

        // Rate Limit چندلایه برای صفحه کامل
        $limits = [
            'full_search_ip:' . $ip => [45, 1],
            'full_search_fingerprint:' . $fingerprint => [35, 1],
        ];

        if ($userId > 0) {
            $limits['full_search_user:' . $userId] = [30, 1];
        }

        foreach ($limits as $key => $conf) {
            if (!$this->rateLimiter->attempt($key, $conf[0], $conf[1])) {
                $this->session->setFlash('error', 'تعداد درخواست‌های شما بیش از حد مجاز است.');
                $this->response->redirect(url('/'));
                return;
            }
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
