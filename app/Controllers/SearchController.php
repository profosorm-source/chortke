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

        // Section 8.8 — limits now sourced from config/rate_limits.php:search.*
        $admUser = get_rate_limit_config('search', 'admin_user');
        $admIp   = get_rate_limit_config('search', 'admin_ip');
        $admFp   = get_rate_limit_config('search', 'admin_fingerprint');
        $limits = [
            'admin_search_user:' . $userId             => [(int)$admUser['max_attempts'], (int)$admUser['decay_minutes']],
            'admin_search_ip:' . $ip                   => [(int)$admIp['max_attempts'],   (int)$admIp['decay_minutes']],
            'admin_search_fingerprint:' . $fingerprint => [(int)$admFp['max_attempts'],   (int)$admFp['decay_minutes']],
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

        // Section 8.8 — limits now sourced from config/rate_limits.php:search.*
        $genCfg = get_rate_limit_config('search', 'general');
        $advCfg = get_rate_limit_config('search', 'advanced');
        $limits = [
            'user_search_ip:' . $ip                   => [(int)$genCfg['max_attempts'], (int)$genCfg['decay_minutes']],
            'user_search_fingerprint:' . $fingerprint => [(int)$advCfg['max_attempts'], (int)$advCfg['decay_minutes']],
        ];

        if ($userId > 0) {
            $limits['user_search_user:' . $userId] = [(int)$advCfg['max_attempts'], (int)$advCfg['decay_minutes']];
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

        // Section 8.8 — limits now sourced from config/rate_limits.php:search.*
        $genCfg = get_rate_limit_config('search', 'general');
        $advCfg = get_rate_limit_config('search', 'advanced');
        $limits = [
            'full_search_ip:' . $ip                   => [(int)$genCfg['max_attempts'] + 15, (int)$genCfg['decay_minutes']],
            'full_search_fingerprint:' . $fingerprint => [(int)$genCfg['max_attempts'] + 5,  (int)$genCfg['decay_minutes']],
        ];

        if ($userId > 0) {
            $limits['full_search_user:' . $userId] = [(int)$advCfg['max_attempts'] + 10, (int)$advCfg['decay_minutes']];
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
