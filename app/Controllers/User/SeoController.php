<?php

namespace App\Controllers\User;

use App\Models\Ads;
use App\Models\SeoExecution;
use App\Services\Search\SearchOrchestrator;
use App\Services\Seo\SeoService;
use App\Services\Shared\DashboardStatsService;

/**
 * SeoController — انجام تسک‌های SEO توسط کاربران (Workers)
 */
class SeoController extends BaseUserController
{
    private Ads $adModel;
    private SeoExecution $executionModel;
    private SearchOrchestrator $searchService;
    private SeoService $seoService;
    private DashboardStatsService $analytics;

    public function __construct(
        Ads $adModel,
        SeoExecution $executionModel,
        SearchOrchestrator $searchService,
        SeoService $seoService,
        DashboardStatsService $analytics
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->adModel = $adModel;
        $this->executionModel = $executionModel;
        $this->searchService = $searchService;
        $this->seoService = $seoService;
        $this->analytics = $analytics;
    }

    /** لیست آگهی‌های فعال */
    public function index(): void
    {
        $userId = (int)user_id();
        $search = trim($this->request->get('search') ?? '');
        $page = max(1, (int)$this->request->get('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $result = $this->searchService->searchAdTasks($search, ['type' => 'seo', 'status' => 'active'], $perPage, $offset);
        $ads = $result['items'] ?? [];
        $total = $result['total'] ?? 0;

        // آمار کاربر
        $stats = $this->executionModel->getUserStats($userId);
        $todayCount = $this->executionModel->countByUserToday($userId);

        view('user.seo.index', [
            'title' => 'تسک‌های SEO',
            'ads' => $ads,
            'stats' => [
                'total' => $stats,
                'today' => $todayCount
            ],
            'search' => $search,
            'page' => $page,
            'totalPages' => (int)ceil($total / $perPage),
            'total' => $total,
        ]);
    }

    /** شروع تسک (AJAX) */
    public function start(): void
    {
        $body = $this->request->body();
        $adId = (int)($body['ad_id'] ?? 0);
        $userId = (int)user_id();

        if ($adId <= 0) {
            $this->response->json(['success' => false, 'message' => 'آگهی نامعتبر']);
            return;
        }

        $result = $this->seoService->startTask($adId, $userId);
        $this->response->json($result);
    }

    /** صفحه اجرای تسک (WebView) */
    public function execute(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $execution = $this->executionModel->findByUser($executionId, $userId);

        if (!$execution) {
            $this->session->setFlash('error', 'تسک یافت نشد');
            redirect(url('/seo'));
            return;
        }

        if ($execution->status !== 'started') {
            $this->session->setFlash('error', 'این تسک قابل انجام نیست');
            redirect(url('/seo'));
            return;
        }

        $ad = $this->adModel->find($execution->ad_id);

        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد');
            redirect(url('/seo'));
            return;
        }

        view('user.seo.execute', [
            'title' => 'اجرای تسک',
            'execution' => $execution,
            'ad' => $ad,
        ]);
    }

    /** تکمیل تسک (AJAX) */
    public function complete(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();
        $body = $this->request->body();

        $engagementData = [
            'duration' => (int)($body['duration'] ?? 0),
            'scroll_depth' => (float)($body['scroll_depth'] ?? 0),
            'interactions' => (int)($body['interactions'] ?? 0),
            'behavior' => [
                'scroll_speed' => (float)($body['scroll_speed'] ?? 0),
                'mouse_pattern' => $body['mouse_pattern'] ?? 'normal',
                'pause_count' => (int)($body['pause_count'] ?? 0),
                'interaction_types' => $body['interaction_types'] ?? [],
            ],
        ];

        $result = $this->seoService->completeTask($executionId, $userId, $engagementData);
        $this->response->json($result);
    }

    /** لغو تسک (AJAX) */
    public function cancel(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $result = $this->seoService->cancelTask($executionId, $userId);
        $this->response->json($result);
    }

    /** تاریخچه تسک‌ها */
    public function history(): void
    {
        $userId = (int)user_id();
        $page = max(1, (int)($this->request->get('page') ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $executions = $this->executionModel->getByUser($userId, $limit, $offset);
        $total = $this->executionModel->countByUser($userId);
        $totalPages = ceil($total / $limit);

        view('user.seo.history', [
            'title' => 'تاریخچه تسک‌ها',
            'executions' => $executions,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ]);
    }

    /** جزئیات یک تسک انجام شده */
    public function showExecution(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();

        $execution = $this->executionModel->findByUser($executionId, $userId);

        if (!$execution) {
            redirect(url('/seo/history'));
            return;
        }

        $ad = $this->adModel->find($execution->ad_id);

        view('user.seo.show-execution', [
            'title' => 'جزئیات تسک',
            'execution' => $execution,
            'ad' => $ad,
        ]);
    }

    /** ثبت گزارش برای تسک سئو (AJAX) */
    public function report(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();
        $body = $this->request->body();
        $reason = trim($body['reason'] ?? '');
        $description = trim($body['description'] ?? '');

        if (empty($reason)) {
            $this->response->json(['success' => false, 'message' => 'دلیل گزارش الزامی است']);
            return;
        }

        $execution = $this->executionModel->findByUser($executionId, $userId);
        if (!$execution) {
            $this->response->json(['success' => false, 'message' => 'تسک یافت نشد']);
            return;
        }

        $result = $this->seoService->reportTask($userId, $execution->ad_id, $reason, $description);
        $this->response->json($result);
    }

    /** ثبت امتیاز برای تسک سئو (AJAX) */
    public function rate(): void
    {
        $executionId = (int)$this->request->param('id');
        $userId = (int)user_id();
        $body = $this->request->body();
        $stars = (int)($body['stars'] ?? 0);
        $comment = trim($body['comment'] ?? '');

        if ($stars < 1 || $stars > 5) {
            $this->response->json(['success' => false, 'message' => 'امتیاز نامعتبر است']);
            return;
        }

        $execution = $this->executionModel->findByUser($executionId, $userId);
        if (!$execution) {
            $this->response->json(['success' => false, 'message' => 'تسک یافت نشد']);
            return;
        }

        $result = $this->seoService->rateTask($userId, $execution->ad_id, $stars, $comment);
        $this->response->json($result);
    }
}