<?php

namespace App\Controllers\Admin;

use App\Models\CustomTaskSubmissionModel;
use App\Models\InteractionModel;
use App\Services\CustomTask\AdminCustomTaskService;
use App\Services\Shared\DisputeService;
use App\Controllers\Admin\BaseAdminController;

class ExecutorTaskController extends BaseAdminController
{
    private AdminCustomTaskService $customTaskService;
    private CustomTaskSubmissionModel $submissionModel;
    private DisputeService $disputeService;
    private InteractionModel $interactionModel;

    public function __construct(
        AdminCustomTaskService $customTaskService,
        CustomTaskSubmissionModel $submissionModel,
        DisputeService $disputeService,
        InteractionModel $interactionModel
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->customTaskService = $customTaskService;
        $this->submissionModel = $submissionModel;
        $this->disputeService = $disputeService;
        $this->interactionModel = $interactionModel;
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

    /**
     * لیست گزارشات تسک‌ها
     */
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

    /**
     * بررسی گزارش
     */
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
