<?php

namespace App\Controllers\Admin;

use App\Models\Ads;
use App\Models\CustomTaskSubmissionModel;
use App\Models\InteractionModel;
use App\Services\Search\SearchOrchestrator;
use App\Services\CustomTaskService;
use App\Services\Analytics\AnalyticsService;
use App\Services\Shared\DisputeService;
use App\Contracts\WalletServiceInterface;
use App\Controllers\Admin\BaseAdminController;

class CustomTaskController extends BaseAdminController
{
    private SearchOrchestrator $searchService;
    private CustomTaskService $customTaskService;
    private AnalyticsService $analyticsService;
    private WalletServiceInterface $walletService;
    private Ads $adsModel;
    private CustomTaskSubmissionModel $submissionModel;
    private DisputeService $disputeService;
    private InteractionModel $interactionModel;

    public function __construct(
        SearchOrchestrator $searchService,
        CustomTaskService $customTaskService,
        AnalyticsService $analyticsService,
        WalletServiceInterface $walletService,
        Ads $adsModel,
        CustomTaskSubmissionModel $submissionModel,
        DisputeService $disputeService,
        InteractionModel $interactionModel
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->searchService = $searchService;
        $this->customTaskService = $customTaskService;
        $this->analyticsService = $analyticsService;
        $this->walletService = $walletService;
        $this->adsModel = $adsModel;
        $this->submissionModel = $submissionModel;
        $this->disputeService = $disputeService;
        $this->interactionModel = $interactionModel;
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

        if (!empty($filters['search'])) {
            $result = $this->searchService->searchAdTasks($filters['search'], array_merge($filters, ['type' => 'custom_task']), $limit, $offset);
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
     * تایید اجباری submission توسط ادمین
     */
    public function forceApproveSubmission(): void
    {

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $submissionId = (int) ($body['submission_id'] ?? 0);

        $submission = $this->submissionModel->submission_find($submissionId);
        if (!$submission) {
            $this->response->json(['ok' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        // استفاده از متد جدید forceApproveSubmissionByAdmin
        $result = $this->customTaskService->forceApproveSubmissionByAdmin(
            $submissionId,
            $this->userId()
        );

        $this->logger->activity('custom_task.force_approve', 'تایید اجباری submission', user_id(), [
            'submission_id' => $submissionId,
            'admin_id' => $this->userId(),
        ]);

        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * رد اجباری submission توسط ادمین
     */
    public function forceRejectSubmission(): void
    {

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $submissionId = (int) ($body['submission_id'] ?? 0);
        $reason = $body['reason'] ?? 'رد توسط ادمین';

        $submission = $this->submissionModel->submission_find($submissionId);
        if (!$submission) {
            $this->response->json(['ok' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        // استفاده از متد جدید forceRejectSubmissionByAdmin
        $result = $this->customTaskService->forceRejectSubmissionByAdmin(
            $submissionId,
            $this->userId(),
            $reason
        );

        $this->logger->activity('custom_task.force_reject', 'رد اجباری submission', user_id(), [
            'submission_id' => $submissionId,
            'admin_id' => $this->userId(),
            'reason' => $reason,
        ]);

        $this->response->json($result, $result['ok'] ? 200 : 422);
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
     * لیست اختلافات
     */
    public function disputes()
    {

        $filters = [
            'status' => $this->request->get('status'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $disputeResult = $this->disputeService->listForAdmin($filters, $limit, $offset);
        $disputes = $disputeResult['items'];
        $total = $disputeResult['total'];

        return view('admin.custom-tasks.disputes', [
            'disputes' => $disputes,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
        ]);
    }

    /**
     * حل اختلاف
     */
    public function resolveDispute(): void
    {

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $disputeId = (int) ($body['dispute_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $adminNote = $body['admin_note'] ?? '';

        if (!in_array($decision, ['executor', 'advertiser'])) {
            $this->response->json(['ok' => false, 'message' => 'تصمیم نامعتبر است.'], 422);
            return;
        }

        $result = $this->disputeService->resolveByAdmin(
            $this->userId(),
            $disputeId,
            $decision,
            $adminNote
        );

        $this->logger->activity('custom_task.resolve_dispute', 'حل اختلاف', user_id(), [
            'dispute_id' => $disputeId,
            'decision' => $decision,
        ]);

        $this->response->json($result, $result['ok'] ? 200 : 422);
    }

    public function reports()
    {

        $filters = [
            'status' => $this->request->get('status'),
            'reason' => $this->request->get('reason'),
        ];

        $page = max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $reports = $this->interactionModel->adminListTaskReports($filters, $limit, $offset);
        $total = $this->interactionModel->adminCountTaskReports($filters);

        return view('admin.custom-tasks.reports', [
            'reports' => $reports,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'filters' => $filters,
            'statusLabels' => $this->interactionModel->taskReportStatusLabels(),
            'reasonLabels' => $this->interactionModel->taskReportReasonLabels(),
        ]);
    }

    public function reviewReport(): void
    {

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $reportId = (int) ($body['report_id'] ?? 0);
        $status = $body['status'] ?? '';
        $adminNote = $body['admin_note'] ?? '';

        if (!in_array($status, ['reviewed', 'resolved', 'rejected'])) {
            $this->response->json(['success' => false, 'message' => 'وضعیت نامعتبر است.'], 422);
            return;
        }

        $report = $this->interactionModel->findTaskReport($reportId);

        if (!$report) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد.'], 404);
            return;
        }

        $updated = $this->interactionModel->updateTaskReport($reportId, [
            'status' => $status,
            'admin_id' => $this->userId(),
            'admin_note' => $adminNote,
            'resolved_at' => ($status === 'resolved') ? date('Y-m-d H:i:s') : null,
        ]);

        if ($updated) {
            // اگر resolved شد، ممکنه تسک رو غیرفعال کنیم
            if ($status === 'resolved' && $report->reason === 'fraud') {
                $this->customTaskService->rejectTask($report->task_id, $this->userId(), 'گزارش شده به دلیل تقلب');
            }

            $this->logger->activity('custom_task.review_report', 'بررسی گزارش', user_id(), [
                'report_id' => $reportId,
                'status' => $status,
            ]);

            $this->response->json(['success' => true, 'message' => 'گزارش با موفقیت بررسی شد.'], 200);
        } else {
            $this->response->json(['success' => false, 'message' => 'خطا در بررسی گزارش.'], 500);
        }
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

    /**
     * عملیات دسته‌جمعی (Batch Operations)
     */
    public function batchAction(): void
    {

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $body['action'] ?? '';
        $ids = $body['ids'] ?? [];

        if (!in_array($action, ['approve_all', 'reject_all', 'pause_all', 'delete_all'])) {
            $this->response->json(['success' => false, 'message' => 'عملیات نامعتبر است.'], 422);
            return;
        }

        if (empty($ids) || !is_array($ids)) {
            $this->response->json(['success' => false, 'message' => 'هیچ موردی انتخاب نشده است.'], 422);
            return;
        }

        $success = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $id = (int) $id;
            
            try {
                switch ($action) {
                    case 'approve_all':
                        $result = $this->customTaskService->forceApproveSubmissionByAdmin($id, $this->userId());
                        break;
                    
                    case 'reject_all':
                        $result = $this->customTaskService->forceRejectSubmissionByAdmin($id, $this->userId(), 'رد دسته‌جمعی توسط مدیریت');
                        break;
                    
                    case 'pause_all':
                        $result = $this->customTaskService->pauseTask($id, $this->userId());
                        break;
                    
                    case 'delete_all':
                        $result = $this->customTaskService->deleteTask($id, $this->userId());
                        break;
                    
                    default:
                        $result = ['ok' => false];
                }

                if ($result['ok'] ?? false) {
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        $this->logger->activity('custom_task.batch_action', 'عملیات دسته‌جمعی', user_id(), [
            'action' => $action,
            'total' => count($ids),
            'success' => $success,
            'failed' => $failed,
        ]);

        $this->response->json([
            'success' => true,
            'message' => "موفق: {$success}، ناموفق: {$failed}",
            'stats' => ['success' => $success, 'failed' => $failed]
        ], 200);
    }
}
