<?php

namespace App\Controllers\User;

use App\Services\TaskExecutionService;
use App\Services\TaskDisputeService;
use App\Services\AdTaskService;
use App\Services\SocialAccountService;
use App\Services\UploadService;
use App\Services\WalletService;

class TaskController extends BaseUserController
{
    private WalletService $walletService;
    private SocialAccountService $socialAccountService;
    private TaskDisputeService $taskDisputeService;
    private TaskExecutionService $taskExecutionService;
    private TaskExecutionService $execService;
    private AdTaskService        $adTaskService;
    private SocialAccountService $socialService;
    private UploadService        $uploadService;

    public function __construct(
        TaskExecutionService $taskExecutionService,
        TaskDisputeService $taskDisputeService,
        SocialAccountService $socialAccountService,
        WalletService $walletService,
        AdTaskService $adTaskService,
        UploadService $uploadService)
    {
        parent::__construct();
        $this->execService = $taskExecutionService;
        $this->adTaskService = $adTaskService;
        $this->socialService = $socialAccountService;
        $this->uploadService = $uploadService;
        $this->taskExecutionService = $taskExecutionService;
        $this->taskDisputeService = $taskDisputeService;
        $this->socialAccountService = $socialAccountService;
        $this->walletService = $walletService;
    }

    public function index()
    {
        $userId         = user_id();
        $socialAccounts = $this->socialService->getByUser($userId);
        $tasks          = $this->adTaskService->getActiveForExecutor($userId, 30);
        $stats          = $this->execService->getUserStats($userId);

        return view('user.tasks.index', [
            'tasks'          => $tasks,
            'socialAccounts' => $socialAccounts,
            'stats'          => $stats,
        ]);
    }

    public function start(): void
    {
        $userId = user_id();
        try {
            rate_limit('task', 'execute', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->response->json(['success' => false, 'message' => $e->getMessage()], 429);
                return;
            }
        }

        $body = $this->request->body();
        $adId = (int)($body['ad_id'] ?? 0);

        if ($adId <= 0) {
            $this->response->json(['success' => false, 'message' => 'تسک نامعتبر.']);
            return;
        }

        $result = $this->execService->start($adId, user_id());
        $this->response->json($result);
    }

    public function showExecute()
    {
        $executionId = (int)$this->request->param('id');
        $execution   = $this->execService->find($executionId);

        if (!$execution || $execution->executor_id !== user_id()) {
            $this->session->setFlash('error', 'تسک یافت نشد.');
            $this->response->redirect(url('/tasks'));
            exit;
        }

        if ($execution->status !== 'started') {
            $this->session->setFlash('error', 'این تسک دیگر قابل انجام نیست.');
            $this->response->redirect(url('/tasks'));
            exit;
        }

        if ($execution->deadline_at && strtotime($execution->deadline_at) < time()) {
            $this->execService->markExpired($executionId);
            $this->session->setFlash('error', 'زمان انجام تسک به پایان رسیده.');
            $this->response->redirect(url('/tasks'));
            exit;
        }

        $task = $this->adTaskService->find($execution->advertisement_id);

        if ($task && $task->task_type === 'view' && $task->platform === 'youtube') {
            return view('user.tasks.execute-video', ['execution' => $execution, 'task' => $task]);
        }

        return view('user.tasks.execute', ['execution' => $execution, 'task' => $task]);
    }

    public function submit(): void
    {
        $userId = user_id();
        try {
            rate_limit('task', 'submit', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->response->json(['success' => false, 'message' => $e->getMessage()], 429);
                return;
            }
        }

        $executionId = (int)$this->request->param('id');
        $proofPath   = null;

        if (!empty($_FILES['proof_image']['name'])) {
            $uploadResult = $this->uploadService->upload($_FILES['proof_image'], 'task-proofs', ['jpg', 'jpeg', 'png', 'webp'], 3 * 1024 * 1024);
            if (!$uploadResult['success']) {
                $this->response->json(['success' => false, 'message' => 'خطا در آپلود تصویر: ' . ($uploadResult['message'] ?? '')]);
                return;
            }
            $proofPath = $uploadResult['path'];
        }

        if (!$proofPath) {
            $this->response->json(['success' => false, 'message' => 'لطفاً اسکرین‌شات ارسال کنید.']);
            return;
        }

        $imagePath     = rtrim(env('UPLOAD_PATH', 'storage/uploads'), '/') . '/' . $proofPath;
        $proofMetadata = [];
        if (file_exists($imagePath)) {
            $proofMetadata['image_hash'] = md5_file($imagePath);
            $proofMetadata['file_size']  = filesize($imagePath);
        }

        $body         = $this->request->body();
        $behaviorData = [];
        if (!empty($body['mouse_data']))    $behaviorData['mouse_data']   = $body['mouse_data'];
        if (!empty($body['time_on_page'])) $behaviorData['time_on_page'] = (int)$body['time_on_page'];

        $result = $this->execService->submit($executionId, user_id(), [
            'proof_image'    => $proofPath,
            'proof_metadata' => $proofMetadata,
            'behavior_data'  => $behaviorData,
        ]);

        $this->response->json($result);
    }

    public function history()
    {
        $userId  = user_id();
        $page    = (int)($_GET['page'] ?? 1);
        $limit   = 20;
        $offset  = ($page - 1) * $limit;
        $filters = [];
        $status  = $_GET['status'] ?? '';
        if ($status) $filters['status'] = $status;

        $total      = $this->execService->countByExecutor($userId, $filters);
        $executions = $this->execService->getByExecutor($userId, $filters, $limit, $offset);
        $stats      = $this->execService->getUserStats($userId);
        $totalPages = (int)ceil($total / $limit);

        return view('user.tasks.history', [
            'executions' => $executions,
            'stats'      => $stats,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
            'status'     => $status,
        ]);
    }

    public function dispute(): void
    {
        $executionId  = (int)$this->request->param('id');
        $body         = $this->request->body();
        $reason       = $body['reason'] ?? '';

        if (empty($reason)) {
            $this->response->json(['success' => false, 'message' => 'لطفاً دلیل اعتراض را وارد کنید.']);
            return;
        }

        $evidencePath = null;
        if (!empty($_FILES['evidence_image']['name'])) {
            $uploadResult = $this->uploadService->upload($_FILES['evidence_image'], 'dispute-evidence', ['jpg', 'jpeg', 'png'], 3 * 1024 * 1024);
            if ($uploadResult['success']) {
                $evidencePath = $uploadResult['path'];
            }
        }

        $disputeService = $this->taskDisputeService;
        $result = $disputeService->open($executionId, user_id(), 'executor', $reason, $evidencePath);
        $this->response->json($result);
    }
}
