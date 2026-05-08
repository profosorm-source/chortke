<?php

namespace App\Controllers\Admin;

use App\Services\CacheAdminService;

class CacheAdminController extends BaseAdminController
{
    private \App\Models\Setting $settingModel;
    private CacheAdminService $cacheService;

    public function __construct(\App\Models\Setting $settingModel, CacheAdminService $cacheService)
    {
        parent::__construct();
        $this->settingModel  = $settingModel;
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
        $body = $this->request->body();
        $type = $body['type'] ?? 'all';
        $tag  = $body['tag'] ?? '';

        $result = $this->cacheService->clear($type, $tag);
        $this->response->json($result);
    }

    public function forget(): void
    {
        $body = $this->request->body();
        $key  = $body['key'] ?? '';

        if ($key !== '') {
            $this->cacheService->forget($key);
        }

        $this->response->json(['success' => true]);
    }
}
