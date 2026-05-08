<?php

namespace App\Controllers\Admin;

use App\Models\CustomTaskModel;
use App\Services\CustomTaskService;
use App\Services\Analytics\AnalyticsService;
use App\Services\WalletService;
use App\Controllers\Admin\BaseAdminController;

class AdTaskController extends BaseAdminController
{
    private CustomTaskService $customTaskService;
    private AnalyticsService $analyticsService;
    private WalletService $walletService;
    private CustomTaskModel $customTaskModel;

    public function __construct(
        CustomTaskService $customTaskService,
        AnalyticsService $analyticsService,
        WalletService $walletService,
        CustomTaskModel $customTaskModel
    ) {
        parent::__construct();
        $this->customTaskService = $customTaskService;
        $this->analyticsService = $analyticsService;
        $this->walletService = $walletService;
        $this->customTaskModel = $customTaskModel;
    }

    /**
     * لیست وظایف
     */
    public function index()
    {
        $filters = [
            'status' => $this->request->get('status'),
            'task_type' => $this->request->get('task_type'),
            'search' => $this->request->get('search'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $tasks = $this->customTaskModel->adminList($filters, $limit, $offset);
        $total = $this->customTaskModel->adminCount($filters);

        return view('admin.custom-tasks.index', [
            'tasks' => $tasks,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
            'statusLabels' => $this->customTaskModel->statusLabels(),
            'statusClasses' => $this->customTaskModel->statusClasses(),
            'taskTypes' => $this->customTaskModel->taskTypes(),
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
            'statusLabels' => $this->customTaskModel->statusLabels(),
            'submissionStatusLabels' => $this->customTaskModel->submissionStatusLabels(),
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
