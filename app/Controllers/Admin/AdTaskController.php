<?php

namespace App\Controllers\Admin;

use App\Models\Ads;
use App\Services\CustomTask\AdminCustomTaskService;
use App\Services\Analytics\AnalyticsService;
use App\Contracts\WalletServiceInterface;
use App\Services\Search\SearchOrchestrator;
use App\Controllers\Admin\BaseAdminController;

class AdTaskController extends BaseAdminController
{
    private AdminCustomTaskService $customTaskService;
    private AnalyticsService $analyticsService;
    private WalletServiceInterface $walletService;
    private Ads $adsModel;
    private SearchOrchestrator $searchService;

    public function __construct(
        AdminCustomTaskService $customTaskService,
        AnalyticsService $analyticsService,
        WalletServiceInterface $walletService,
        Ads $adsModel,
        SearchOrchestrator $searchService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->customTaskService = $customTaskService;
        $this->analyticsService = $analyticsService;
        $this->walletService = $walletService;
        $this->adsModel = $adsModel;
        $this->searchService = $searchService;
    }

    /**
     * لیست وظایف
     */
    public function index()
    {
        $filters = [
            'status' => $this->request->get('status'),
            'task_type' => $this->request->get('task_type'),
        ];

        $search = trim($this->request->get('search') ?? '');
        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        // استفاده از SearchOrchestrator برای جستجو
        if (!empty($search)) {
            $result = $this->searchService->searchAdTasks($search, $filters, $limit, $offset);
            $tasks = $result['items'] ?? [];
            $total = $result['total'] ?? 0;
        } else {
            $tasks = $this->adsModel->adminList('custom_task', $filters['status'] ?? '', $limit, $offset);
            $total = $this->adsModel->adminCount('custom_task', $filters['status'] ?? '');
        }

        return view('admin.custom-tasks.index', [
            'tasks' => $tasks,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
            'search' => $search,
            'statusLabels' => $this->adsModel->statusLabels(),
            'statusClasses' => $this->adsModel->statusClasses(),
            'taskTypes' => $this->adsModel->taskTypes(),
        ]);
    }

    /**
     * جزئیات وظیفه
     */
    public function show()
    {
        $taskId = (int) $this->request->param('id');
        $details = $this->customTaskService->getTaskDetailsForAdmin($taskId);

        if (!$details) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        return view('admin.custom-tasks.show', [
            'task' => $details['task'],
            'submissions' => $details['submissions'],
            'statusLabels' => $this->adsModel->statusLabels(),
            'submissionStatusLabels' => $this->adsModel->submissionStatusLabels(),
        ]);
    }

    /**
     * تأیید/رد وظیفه (Ajax)
     */
    public function approve(): void
    {
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $taskId = (int) ($body['task_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $reason = $body['reason'] ?? null;

        if ($decision === 'approve') {
            $result = $this->customTaskService->approveTask($taskId, $this->userId());

            $this->logger->activity('custom_task.approve', 'تأیید وظیفه', user_id(), ['task_id' => $taskId]);
            $this->response->json($result, $result['success'] ? 200 : 422);

        } elseif ($decision === 'reject') {
            $result = $this->customTaskService->rejectTask($taskId, $this->userId(), $reason);

            $this->logger->activity('custom_task.reject', 'رد وظیفه', user_id(), ['task_id' => $taskId, 'reason' => $reason]);
            $this->response->json($result, $result['success'] ? 200 : 422);

        } else {
            $this->response->json(['success' => false, 'message' => 'تصمیم نامعتبر.'], 422);
        }
    }

    /**
     * آمار و گزارش
     */
    public function stats(): void
    {
        $result = $this->customTaskService->getAdminStats();
        $this->response->json($result);
    }

    /**
     * داشبورد آمار کلی
     */
    public function analytics()
    {
        $analyticsData = $this->customTaskService->getAdminAnalytics();

        // تسک‌های پرطرفدار
        $trending = $this->analyticsService->getTrendingTasks(10);

        return view('admin.custom-tasks.analytics', [
            'taskStats' => $analyticsData['taskStats'],
            'submissionStats' => $analyticsData['submissionStats'],
            'trending' => $trending,
        ]);
    }
}
