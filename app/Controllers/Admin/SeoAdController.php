<?php
namespace App\Controllers\Admin;
use App\Models\Ads;
use App\Models\SeoExecution;
use App\Services\Shared\DashboardStatsService;

/**
 * Admin — مدیریت آگهی‌های SEO
 */
class SeoAdController extends BaseAdminController
{
    private Ads $model;
    private SeoExecution $executionModel;
    private DashboardStatsService $analytics;
    private \App\Services\Seo\AdsSeoService $seoService;

    public function __construct(
        Ads $m, 
        SeoExecution $e,
        DashboardStatsService $a,
        \App\Services\Seo\AdsSeoService $s
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->model = $m;
        $this->executionModel = $e;
        $this->analytics = $a;
        $this->seoService = $s;
    }

    public function index(): void
    {
        $status = $this->request->get('status') ?? '';
        // استفاده از فیلتر نوع seo در متد جدید adminList
        $items = $this->model->adminList('seo', $status, 30, 0);
        
        // آمار کلی با Shared Analytics از جدول یکپارچه ads
        $overview = $this->analytics->getTrend('seo_executions', 'created_at', 30);
        $totalAds = $this->analytics->getCount('ads', ['type' => 'seo']);
        $activeAds = $this->analytics->getCount('ads', ['type' => 'seo', 'status' => 'active']);
        
        view('admin.seo-ad.index', [
            'title' => 'مدیریت آگهی‌های SEO',
            'items' => $items,
            'status' => $status,
            'stats' => [
                'total_ads' => $totalAds,
                'active_ads' => $activeAds,
                'trend' => $overview
            ],
        ]);
    }

    public function approve(): void
    {
        $ok = $this->adsSeoService->approveAd((int)$this->request->param('id'));
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }

    public function reject(): void
    {
        $reason = trim($this->request->post('reason') ?? '');
        $ok = $this->adsSeoService->rejectAd((int)$this->request->param('id'), $reason);
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }

    public function pause(): void
    {
        $ok = $this->adsSeoService->pauseAd((int)$this->request->param('id'));
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }
}