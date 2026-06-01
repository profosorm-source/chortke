<?php

namespace App\Controllers\User;

use App\Models\Ads;
use App\Models\SeoExecution;
use App\Contracts\WalletServiceInterface;
use App\Services\Shared\DashboardStatsService;
use App\Services\SeoPayoutService;
use App\Services\AdSystemManager;

/**
 * SeoAdController — مدیریت آگهی‌های SEO توسط تبلیغ‌دهنده
 */
class SeoAdController extends BaseUserController
{
    private Ads $model;
    private SeoExecution $executionModel;
    private WalletServiceInterface $wallet;
    private DashboardStatsService $analytics;
    private SeoPayoutService $payoutService;
    private AdSystemManager $adManager;
    private \App\Services\Seo\AdsSeoService $adsSeoService;

    public function __construct(
        Ads $m,
        SeoExecution $e,
        WalletServiceInterface $w,
        DashboardStatsService $a,
        SeoPayoutService $p,
        AdSystemManager $adManager,
        \App\Services\Seo\AdsSeoService $seoService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->model = $m;
        $this->executionModel = $e;
        $this->wallet = $w;
        $this->analytics = $a;
        $this->payoutService = $p;
        $this->adManager = $adManager;
        $this->adsSeoService = $seoService;
    }

    /** لیست آگهی‌های من */
    public function index(): void
    {
        $userId = (int)user_id();
        $ads = $this->model->getByAdvertiser($userId, 30, 0, 'seo');
        
        // استفاده از Shared Analytics برای آمار
        $statsData = $this->analytics->getAggregates('seo_executions', [
            'ad_id' => ['IN', array_column($ads, 'id')]
        ], [
            'COUNT(*) as total_executions',
            'SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed',
            'SUM(CASE WHEN status = "completed" THEN payout_amount ELSE 0 END) as total_spent',
            'AVG(CASE WHEN status = "completed" THEN final_score END) as avg_score'
        ]);
        
        view('user.seo-ad.index', [
            'title' => 'آگهی‌های SEO من',
            'ads' => $ads,
            'stats' => (object)$statsData,
        ]);
    }

    /** فرم ثبت آگهی جدید */
    public function create(): void
    {
        view('user.seo-ad.create', [
            'title' => 'ثبت آگهی SEO',
            'minBudget' => (float)setting('seo_ad_min_budget', 50000),
            'minPayout' => (float)setting('seo_ad_min_payout', 1000),
            'maxPayout' => (float)setting('seo_ad_max_payout', 10000),
        ]);
    }

    /** ذخیره آگهی جدید */
    public function store(): void
    {
        $uid = (int)user_id();
        $data = $this->request->body();

        // اعتبارسنجی
        $budget = (float)($data['budget'] ?? 0);
        $minPayout = (float)($data['min_payout'] ?? 0);
        $maxPayout = (float)($data['max_payout'] ?? 0);
        
        $minBudgetRequired = (float)setting('seo_ad_min_budget', 50000);
        $minPayoutAllowed = (float)setting('seo_ad_min_payout', 1000);
        $maxPayoutAllowed = (float)setting('seo_ad_max_payout', 10000);

        if (empty($data['keyword'])) {
            $this->session->setFlash('error', 'کلمه کلیدی الزامی است.');
            redirect(url('/seo-ad/create')); return;
        }

        if (empty($data['site_url']) || !filter_var($data['site_url'], FILTER_VALIDATE_URL)) {
            $this->session->setFlash('error', 'آدرس سایت معتبر نیست.');
            redirect(url('/seo-ad/create')); return;
        }

        if ($budget < $minBudgetRequired) {
            $this->session->setFlash('error', 'حداقل بودجه ' . number_format($minBudgetRequired) . ' تومان است.');
            redirect(url('/seo-ad/create')); return;
        }

        if ($minPayout < $minPayoutAllowed || $minPayout > $maxPayoutAllowed) {
            $this->session->setFlash('error', "حداقل پرداخت باید بین " . number_format($minPayoutAllowed) . " تا " . number_format($maxPayoutAllowed) . " تومان باشد.");
            redirect(url('/seo-ad/create')); return;
        }

        if ($maxPayout < $minPayout || $maxPayout > $maxPayoutAllowed) {
            $this->session->setFlash('error', "حداکثر پرداخت باید بیشتر از حداقل و حداکثر " . number_format($maxPayoutAllowed) . " تومان باشد.");
            redirect(url('/seo-ad/create')); return;
        }

        // پیش‌بینی بودجه
        $estimation = $this->payoutService->estimateBudget([
            'min_payout' => $minPayout,
            'max_payout' => $maxPayout,
            'expected_users' => 100,
            'avg_score' => 70,
        ]);

        if ($budget < $estimation['recommended_budget'] * 0.5) {
            $this->session->setFlash('warning', 'توجه: بودجه شما ممکن است برای تعداد کاربران مورد نظر کافی نباشد.');
        }

        // کسر از کیف پول از طریق API رسمی و ثبت آگهی در تراکنش واحد
        $createResult = $this->adsSeoService->createAd($uid, $data, $budget, $minPayout, $maxPayout);
        
        if (!empty($createResult['success'])) {
            $this->session->setFlash('success', 'آگهی SEO ثبت شد و پس از تایید مدیر فعال می‌شود.');
            redirect(url('/seo-ad'));
        } else {
            $this->session->setFlash('error', $createResult['message'] ?? 'خطا در ثبت آگهی.');
            redirect(url('/seo-ad/create'));
        }
    }

    /** جزئیات آگهی + آنالیتیکس */
    public function show(): void
    {
        $adId = (int)$this->request->param('id');
        $userId = (int)user_id();
        
        $ad = $this->model->findByIdAndUser($adId, $userId);
            
        if (!$ad) { redirect(url('/seo-ad')); return; }

        // آمار اجراها
        $stats = $this->executionModel->getAdStats($adId);
        
        // توزیع امتیازات
        $scoreDistribution = $this->analytics->getDistribution(
            'seo_executions',
            'final_score',
            ['ad_id' => $adId, 'status' => 'completed'],
            10
        );
        
        // روند زمانی
        $timeline = $this->analytics->getTrend(
            'seo_executions',
            'created_at',
            30,
            ['ad_id' => $adId, 'status' => 'completed']
        );
        
        // پیش‌بینی
        $avgPayout = $stats->completed > 0 ? $stats->total_spent / $stats->completed : 0;
        $estimatedReach = $ad->remaining_budget > 0 && $avgPayout > 0 
            ? floor($ad->remaining_budget / $avgPayout) 
            : 0;

        view('user.seo-ad.show', [
            'title' => 'جزئیات آگهی',
            'data' => [
                'ad' => $ad,
                'stats' => $stats,
                'score_distribution' => $scoreDistribution,
                'timeline' => $timeline['data'] ?? [],
                'predictions' => [
                    'avg_payout' => round($avgPayout, 2),
                    'estimated_reach' => $estimatedReach,
                    'completion_rate' => $stats->total_executions > 0 
                        ? round(($stats->completed / $stats->total_executions) * 100, 2) 
                        : 0,
                ],
            ]
        ]);
    }

    /** توقف موقت */
    public function pause(): void
    {
        $this->model->updateStatusByUser((int)$this->request->param('id'), (int)user_id(), 'paused');
        
        if (is_ajax()) {
            $this->response->json(['success' => true]);
            return;
        }
        
        redirect(url('/seo-ad'));
    }

    /** ادامه */
    public function resume(): void
    {
        $this->model->updateStatusByUser((int)$this->request->param('id'), (int)user_id(), 'active');
        
        if (is_ajax()) {
            $this->response->json(['success' => true]);
            return;
        }
        
        redirect(url('/seo-ad'));
    }

    /** دانلود گزارش CSV */
    public function exportCsv(): void
    {
        $adId = (int)$this->request->param('id');
        $userId = (int)user_id();
        
        $csv = $this->analytics->exportToCsv($adId, $userId);
        
        if (!$csv) {
            $this->session->setFlash('error', 'خطا در دریافت گزارش');
            redirect(url('/seo-ad/' . $adId));
            return;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-ad-' . $adId . '.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo $csv;
        exit;
    }
}