<?php
namespace App\Controllers\Admin;
use App\Models\SeoAd;
use App\Models\SeoExecution;
use App\Services\Shared\AnalyticsService;

/**
 * Admin — مدیریت آگهی‌های SEO
 */
class SeoAdController extends BaseAdminController
{
    private SeoAd $model;
    private SeoExecution $executionModel;
    private AnalyticsService $analytics;

    public function __construct(
        SeoAd $m, 
        SeoExecution $e,
        AnalyticsService $a
    ) {
        parent::__construct();
        $this->model = $m;
        $this->executionModel = $e;
        $this->analytics = $a;
    }

    public function index(): void
    {
        $status = $this->request->get('status') ?? '';
        $items = $this->model->adminList($status, 30, 0);
        
        // آمار کلی با Shared Analytics
        $overview = $this->analytics->getTrend('seo_executions', 'created_at', 30);
        $totalAds = $this->analytics->getCount('seo_ads');
        $activeAds = $this->analytics->getCount('seo_ads', ['status' => 'active']);
        
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
        $ok = $this->model->setStatus((int)$this->request->param('id'), 'active');
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }

    public function reject(): void
    {
        $reason = trim($this->request->post('reason') ?? '');
        $ok = $this->model->setStatus(
            (int)$this->request->param('id'), 'rejected',
            $reason ?: 'مدیر رد کرد'
        );
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }

    public function pause(): void
    {
        $ok = $this->model->setStatus((int)$this->request->param('id'), 'paused');
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }
}