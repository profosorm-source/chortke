<?php

namespace App\Controllers\User;

use App\Controllers\User\BaseUserController;
use App\Models\Ads;
use App\Services\CustomTask\CustomTaskService as CustomTaskCoreService;
use App\Services\CustomTask\CustomTaskModerationService;
use App\Validators\Requests\CreateCustomTaskRequest;
use App\Services\AntiFraud\GeoIPService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AdSystemManager;

class CustomTaskAdController extends BaseUserController
{
    private CustomTaskCoreService $coreService;
    private CustomTaskModerationService $moderationService;
    private GeoIPService $ipQualityService;
    private BrowserFingerprintService $fingerprintService;
    private AdSystemManager $adManager;
    private Ads $adsModel;

    public function __construct(
        CustomTaskCoreService $coreService,
        CustomTaskModerationService $moderationService,
        GeoIPService $ipQualityService,
        BrowserFingerprintService $fingerprintService,
        AdSystemManager $adManager,
        Ads $adsModel
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->coreService = $coreService;
        $this->moderationService = $moderationService;
        $this->ipQualityService = $ipQualityService;
        $this->fingerprintService = $fingerprintService;
        $this->adManager = $adManager;
        $this->adsModel = $adsModel;
    }

    /**
     * لیست تسک‌های تبلیغ‌دهنده
     */
    public function index()
    {
        $userId = $this->userId();
        $tasks = $this->coreService->getMyTasks($userId, null, 30, 0);

        return view('user.custom-tasks.ad.index', [
            'tasks' => $tasks,
        ]);
    }

    /**
     * فرم ایجاد تسک
     */
    public function create()
    {
        return view('user.custom-tasks.ad.create');
    }

    /**
     * ذخیره تسک جدید
     */
    public function store(): string
    {
        $userId = $this->userId();

        $payload = [
            'title' => trim((string) ($this->request->post('title') ?? '')),
            'description' => trim((string) ($this->request->post('description') ?? '')),
            'link' => trim((string) ($this->request->post('link') ?? '')),
            'task_type' => trim((string) ($this->request->post('task_type') ?? 'custom')),
            'proof_type' => trim((string) ($this->request->post('proof_type') ?? 'screenshot')),
            'proof_description' => trim((string) ($this->request->post('proof_description') ?? '')),
            'price_per_task' => (float) ($this->request->post('price_per_task') ?? 0),
            'currency' => trim((string) ($this->request->post('currency') ?? 'irt')),
            'total_quantity' => (int) ($this->request->post('total_quantity') ?? 0),
            'deadline_hours' => (int) ($this->request->post('deadline_hours') ?? 24),
            'daily_limit_per_user' => (int) ($this->request->post('daily_limit_per_user') ?? 1),
        ];

        // Validation با استفاده از FormRequest استاندارد
        $request = new CreateCustomTaskRequest($payload);
        if (!$request->validate()) {
            $firstError = '';
            foreach ($request->errors() as $fieldErrors) {
                $firstError = is_array($fieldErrors) ? ($fieldErrors[0] ?? '') : (string)$fieldErrors;
                if ($firstError) break;
            }
            $this->session->setFlash('error', $firstError ?: 'داده‌ها نامعتبر است.');
            return redirect('/custom-tasks/ad/create');
        }
        $payload = $request->validated();

        // ایجاد تسک
        $result = $this->coreService->createTask($userId, $payload);

        if (!$result['success']) {
            $this->session->setFlash('error', $result['message'] ?? 'ثبت تسک ناموفق بود.');
            return redirect('/custom-tasks/ad/create');
        }

        $this->session->setFlash('success', 'تسک با موفقیت ایجاد شد.');
        return redirect('/custom-tasks/ad');
    }

    /**
     * نمایش جزئیات تسک و submission ها
     */
    public function show()
    {
        $userId = $this->userId();
        $taskId = (int) $this->request->param('id');
        
        $task = $this->coreService->find($taskId);

        if (!$task || $task->user_id !== $userId) {
            http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        // گرفتن submission ها از طریق سرویس یکپارچه
        $submissions = $this->coreService->getSubmissionsByTask($taskId, null, 50, 0);

        return view('user.custom-tasks.ad.show', [
            'task' => $task,
            'submissions' => $submissions,
        ]);
    }

    /**
     * تایید submission
     */
    public function approveSubmission(): string
    {
        $userId = $this->userId();
        $submissionId = (int) $this->request->post('submission_id');
        $note = trim((string) ($this->request->post('note') ?? ''));

        $result = $this->moderationService->reviewSubmission($submissionId, $userId, 'approve', $note);

        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        return redirect_back();
    }

    /**
     * رد submission
     */
    public function rejectSubmission(): string
    {
        $userId = $this->userId();
        $submissionId = (int) $this->request->post('submission_id');
        $reason = trim((string) ($this->request->post('reason') ?? ''));

        if (empty($reason)) {
            $this->session->setFlash('error', 'دلیل رد الزامی است.');
            return redirect_back();
        }

        $result = $this->moderationService->reviewSubmission($submissionId, $userId, 'reject', $reason);

        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        return redirect_back();
    }

    /**
     * متوقف کردن تسک
     */
    public function pause(): string
    {
        $userId = $this->userId();
        $taskId = (int) $this->request->post('task_id');

        $task = $this->coreService->find($taskId);
        if (!$task || $task->user_id !== $userId) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect('/custom-tasks/ad');
        }

        $this->adsModel->update($taskId, ['status' => 'paused']);

        $this->session->setFlash('success', 'تسک متوقف شد.');
        return redirect('/custom-tasks/ad/' . $taskId);
    }

    /**
     * فعال کردن مجدد تسک
     */
    public function resume(): string
    {
        $userId = $this->userId();
        $taskId = (int) $this->request->post('task_id');

        $task = $this->coreService->find($taskId);
        if (!$task || $task->user_id !== $userId) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect('/custom-tasks/ad');
        }

        $this->adsModel->update($taskId, ['status' => 'active']);

        $this->session->setFlash('success', 'تسک فعال شد.');
        return redirect('/custom-tasks/ad/' . $taskId);
    }
}