<?php

namespace App\Controllers\User;

use App\Controllers\User\BaseUserController;
use App\Models\CustomTaskModel;
use App\Services\CustomTaskService;
use App\Validators\CustomTaskValidator;
use App\Services\AntiFraud\GeoIPService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AdSystemManager;

class CustomTaskAdController extends BaseUserController
{
    private CustomTaskService $service;
    private GeoIPService $ipQualityService;
    private BrowserFingerprintService $fingerprintService;
    private AdSystemManager $adManager;
    private CustomTaskModel $taskModel;

    public function __construct(
        CustomTaskService $service,
        GeoIPService $ipQualityService,
        BrowserFingerprintService $fingerprintService,
        AdSystemManager $adManager,
        CustomTaskModel $taskModel
    ) {
        parent::__construct();
        $this->service = $service;
        $this->ipQualityService = $ipQualityService;
        $this->fingerprintService = $fingerprintService;
        $this->adManager = $adManager;
        $this->taskModel = $taskModel;
    }

    /**
     * لیست تسک‌های تبلیغ‌دهنده
     */
    public function index()
    {
        $userId = $this->userId();
        $tasks = $this->service->getMyTasks($userId, null, 30, 0);

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

        // Validation
        $errors = CustomTaskValidator::validateCreate($payload);
        if (!empty($errors)) {
            $this->session->setFlash('error', $errors[array_key_first($errors)][0] ?? 'داده‌ها نامعتبر است.');
            return redirect('/custom-tasks/ad/create');
        }

        // ایجاد تسک
        $result = $this->service->createTask($userId, $payload);

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
        
        $task = $this->service->find($taskId);

        if (!$task || $task->creator_id !== $userId) {
            http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        // گرفتن submission ها
        $submissions = $this->taskModel->submission_getByTask($taskId, null, 50, 0);

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

        $result = $this->service->reviewSubmission($submissionId, $userId, 'approve', $note);

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

        $result = $this->service->reviewSubmission($submissionId, $userId, 'reject', $reason);

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

        $task = $this->service->find($taskId);
        if (!$task || $task->creator_id !== $userId) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect('/custom-tasks/ad');
        }

        $this->taskModel->update($taskId, ['status' => 'paused']);

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

        $task = $this->service->find($taskId);
        if (!$task || $task->creator_id !== $userId) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            return redirect('/custom-tasks/ad');
        }

        $this->taskModel->update($taskId, ['status' => 'active']);

        $this->session->setFlash('success', 'تسک فعال شد.');
        return redirect('/custom-tasks/ad/' . $taskId);
    }
}