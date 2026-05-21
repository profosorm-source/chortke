<?php

namespace App\Controllers\User;

use App\Services\CustomTask\CustomTaskService as CustomTaskCoreService;
use App\Services\CustomTask\CustomTaskExecutorService;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Services\Analytics\AnalyticsService;
use App\Services\UploadService;
use App\Validators\Requests\CreateCustomTaskRequest;
use App\Validators\Requests\SubmitCustomTaskProofRequest;
use App\Validators\Requests\RateCustomTaskRequest;
use App\Controllers\User\BaseUserController;
use App\Models\Ads;
use Core\Logger;

class CustomTaskController extends BaseUserController
{
    private CustomTaskCoreService $coreService;
    private CustomTaskExecutorService $executorService;
    private CustomTaskModerationService $moderationService;
    private AnalyticsService $analyticsService;
    private UploadService $uploadService;
    private Ads $adsModel;
    private Logger $logger;

    public function __construct(
        CustomTaskCoreService $coreService,
        CustomTaskExecutorService $executorService,
        CustomTaskModerationService $moderationService,
        AnalyticsService $analyticsService,
        UploadService $uploadService,
        Ads $adsModel,
        Logger $logger
    ) {
        parent::__construct();
        $this->coreService = $coreService;
        $this->executorService = $executorService;
        $this->moderationService = $moderationService;
        $this->analyticsService = $analyticsService;
        $this->uploadService = $uploadService;
        $this->adsModel = $adsModel;
        $this->logger = $logger;
    }

    public function create()
    {
        return view('user.custom-tasks.ad.create', [
            'taskTypes' => $this->adsModel->taskTypes(),
            'proofTypes' => $this->adsModel->proofTypes(),
        ]);
    }

    /**
     * ذخیره تسک جدید - با Request Validation
     */
    public function store()
    {
        $userId = $this->userId();

        $request = new CreateCustomTaskRequest($this->request->all());

        if (!$request->validate()) {
            $this->session->setFlash('error', 'خطای اعتبارسنجی');
            $this->session->setFlash('errors', $request->errors());
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/custom-tasks/ad/create'));
        }

        $data = $request->validated();

        $result = $this->coreService->createTask($userId, $data);

        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/custom-tasks/ad/create'));
        }

        $this->logger->activity('custom_task.create', 'ثبت وظیفه جدید', $userId, ['task_id' => $result['task']->id ?? null]);
        $this->session->setFlash('success', $result['message']);
        return redirect(url('/custom-tasks'));
    }

    /**
     * ارسال مدرک - با Request Validation
     */
    public function submitProof()
    {
        $userId = $this->userId();
        $subId = (int) $this->request->param('id');

        $request = new SubmitCustomTaskProofRequest($this->request->all());
        if (!$request->validate()) {
            $this->response->json([
                'success' => false,
                'message' => 'خطای اعتبارسنجی',
                'errors' => $request->errors()
            ], 422);
            return;
        }

        $proofData = $request->validated();

        // مدیریت آپلود فایل
        if (!empty($_FILES['proof_file']['name'])) {
            $uploadResult = $this->uploadService->upload($_FILES['proof_file'], 'task-proofs', ['jpg','png','webp','pdf'], 5*1024*1024);
            if ($uploadResult['success']) {
                $proofData['proof_file'] = $uploadResult['path'];
                $proofData['proof_file_hash'] = md5_file(__DIR__ . '/../../../' . $uploadResult['path']) ?? null;
            }
        }

        $result = $this->executorService->submitProof($subId, $userId, $proofData);
        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * امتیازدهی - با Request Validation
     */
    public function rateSubmission()
    {
        $userId = $this->userId();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $request = new RateCustomTaskRequest($body);
        if (!$request->validate()) {
            $this->response->json([
                'success' => false,
                'message' => 'خطای اعتبارسنجی',
                'errors' => $request->errors()
            ], 422);
            return;
        }

        $result = $this->moderationService->rateSubmission(
            (int)$body['submission_id'],
            $userId,
            $request->validated()
        );

        $this->response->json($result, $result['success'] ? 200 : 422);
    }
}
