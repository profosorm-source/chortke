<?php

namespace App\Controllers\Admin;

use App\Services\CacheAdminService;

class CacheAdminController extends BaseAdminController
{

    private CacheAdminService $cacheService;

    public function __construct(CacheAdminService $cacheService, ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);

        $this->cacheService  = $cacheService;
    }

    public function index(): void
    {
        $stats = $this->cacheService->getStats();

        view('admin/cache/index', [
            'title' => 'مدیریت Cache',
            'stats' => $stats,
        ]);
    }

    public function clear(): void
    {
        $this->validateCsrf();
        $body = $this->request->body();
        $type = $body['type'] ?? 'all';
        $tag  = $body['tag'] ?? '';

        $result = $this->cacheService->clear($type, $tag);
        $this->response->json($result);
    }

    public function forget(): void
    {
        $this->validateCsrf();
        $body = $this->request->body();
        $key  = $body['key'] ?? '';

        if ($key !== '') {
            $this->cacheService->forget($key);
        }

        $this->response->json(['success' => true]);
    }

    /**
     * ریست کردن Circuit Breaker
     * 🛡️ Fix: Explicitly call validateCsrf and handle exception to prevent bypass (MEDIUM-04)
     */
    public function resetCircuitBreaker(): void
    {
        $this->validateCsrf();
        
        $body = $this->request->body();
        $name = $body['name'] ?? '';

        if ($name === '') {
            $this->response->json(['success' => false, 'message' => 'نام Circuit Breaker الزامی است.'], 400);
            return;
        }

        $success = $this->cacheService->resetCircuitBreaker($name);
        
        if ($success) {
            $this->response->json(['success' => true, 'message' => 'Circuit Breaker با موفقیت ریست شد.']);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در ریست کردن Circuit Breaker.'], 500);
        }
    }
}
