<?php

namespace App\Controllers\User;

use App\Services\CustomTaskService;
use App\Services\Analytics\AnalyticsService;
use App\Services\UploadService;
use Core\Validator;
use App\Controllers\User\BaseUserController;

class CustomTaskController extends BaseUserController
{
    private CustomTaskService $customTaskService;
    private AnalyticsService $analyticsService;
    private UploadService $uploadService;

    public function __construct(
        CustomTaskService $customTaskService,
        AnalyticsService $analyticsService,
        UploadService $uploadService
    ) {
        parent::__construct();
        $this->customTaskService = $customTaskService;
        $this->analyticsService = $analyticsService;
        $this->uploadService = $uploadService;
    }

    /**
     * لیست وظایف تبلیغ‌دهنده
     */
    public function index()
    {
        $userId = $this->userId();

        $myTasks = $this->customTaskService->getMyTasks($userId, null, 30, 0);
        $statusLabelsMap = $this->customTaskService->getStatusLabels();
        $statusClassesMap = $this->customTaskService->getStatusClasses();
        $taskTypesMap = $this->customTaskService->getTaskTypes();
        $proofTypesMap = $this->customTaskService->getProofTypes();

        return view('user.custom-tasks.index', [
            'myTasks' => $myTasks,
            'statusLabelsMap' => $statusLabelsMap,
            'statusClassesMap' => $statusClassesMap,
            'taskTypesMap' => $taskTypesMap,
            'proofTypesMap' => $proofTypesMap,
        ]);
    }

    /**
     * لیست وظایف موجود برای انجام
     */
    public function available()
    {
        $userId = $this->userId();
        $filters = ['task_type' => $this->request->get('type')];
        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;
        
        $tasks = $this->customTaskService->getAvailableTasks($userId, $filters, $limit, $offset);
        $total = $this->customTaskService->countAvailableTasks($userId, $filters);
        
        return view('user.custom-tasks.executor.available', [
            'tasks' => $tasks,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
        ]);
    }

    /**
     * فرم ایجاد وظیفه
     */
    public function create()
    {
        return view('user.custom-tasks.ad.create', [
            'taskTypes' => $this->customTaskService->getTaskTypes(),
            'proofTypes' => $this->customTaskService->getProofTypes(),
        ]);
    }

    /**
     * ذخیره وظیفه جدید
     */
    public function store(): string
    {
        $userId = $this->userId();

        // Rate Limiting
        try {
            rate_limit('task', 'create', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                return redirect(url('/custom-tasks/ad/create'));
            }
        }

        // CSRF
        if (!verify_csrf_token($this->request->post('csrf_token'))) {
            $this->session->setFlash('error', 'توکن امنیتی نامعتبر.');
            return redirect(url('/custom-tasks/ad/create'));
        }

        // Validation
        $validator = new Validator($this->request->all(), [
            'title' => 'required|min:5|max:200',
            'description' => 'required|min:20',
            'price_per_task' => 'required|numeric',
            'total_quantity' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'خطا');
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/custom-tasks/ad/create'));
        }

        $data = $validator->data();

        // آپلود تصویر نمونه
        $sampleImage = null;
        if (!empty($_FILES['sample_image']['name'])) {
            $result = $this->uploadService->upload(
                $_FILES['sample_image'],
                'task-samples',
                ['jpg', 'jpeg', 'png', 'webp'],
                2 * 1024 * 1024
            );
            if ($result['success']) {
                $sampleImage = $result['path'];
            }
        }

        $currencyMode = setting('currency_mode', 'irt');

        // ایجاد تسک
        $result = $this->customTaskService->createTask($userId, [
            'title' => $data['title'],
            'description' => $data['description'],
            'link' => $this->request->post('link'),
            'task_type' => $this->request->post('task_type') ?? 'custom',
            'proof_type' => $this->request->post('proof_type') ?? 'screenshot',
            'proof_description' => $this->request->post('proof_description'),
            'sample_image' => $sampleImage,
            'price_per_task' => (float) $data['price_per_task'],
            'currency' => $currencyMode,
            'total_quantity' => (int) $data['total_quantity'],
            'deadline_hours' => (int) ($this->request->post('deadline_hours') ?? 24),
            'device_restriction' => $this->request->post('device_restriction') ?? 'all',
            'daily_limit_per_user' => (int) ($this->request->post('daily_limit_per_user') ?? 1),
        ]);

        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->session->setFlash('old', $this->request->all());
            return redirect(url('/custom-tasks/ad/create'));
        }

        $this->logger->activity('custom_task.create', 'ثبت وظیفه جدید', user_id(), ['task_id' => $result['task']->id ?? null]);
        $this->session->setFlash('success', $result['message']);
        return redirect(url('/custom-tasks'));
    }

    /**
     * جزئیات وظیفه + لیست submission‌ها
     */
    public function show()
    {
        $userId = $this->userId();
        $taskId = (int) $this->request->param('id');

        $task = $this->customTaskService->find($taskId);
        if (!$task) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        $submissions = $this->customTaskService->getSubmissionsByTask($taskId, null, 50, 0);
        $isOwner = ((int) $task->creator_id === $userId);

        return view('user.custom-tasks.ad.show', [
            'task' => $task,
            'submissions' => $submissions,
            'isOwner' => $isOwner,
        ]);
    }

    /**
     * شروع انجام تسک (Ajax)
     */
    public function start(): void
    {
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $taskId = (int) ($body['task_id'] ?? 0);
        $userId = $this->userId();

        $result = $this->customTaskService->startTask($taskId, $userId);
        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * ارسال مدرک (Ajax)
     */
    public function submitProof(): void
    {
        $userId = $this->userId();
        $subId = (int) $this->request->param('id');

        $proofData = ['proof_text' => $this->request->post('proof_text')];

        // آپلود فایل
        if (!empty($_FILES['proof_file']['name'])) {
            $result = $this->uploadService->upload(
                $_FILES['proof_file'],
                'task-proofs',
                ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
                5 * 1024 * 1024
            );
            
            if ($result['success']) {
                $proofData['proof_file'] = $result['path'];
                
                // هش تصویر
                $fullPath = __DIR__ . '/../../../' . $result['path'];
                if (\file_exists($fullPath)) {
                    $proofData['proof_file_hash'] = \md5_file($fullPath);
                }
            } else {
                $this->response->json(['success' => false, 'message' => 'خطا در آپلود فایل.'], 422);
                return;
            }
        }

        $result = $this->customTaskService->submitProof($subId, $userId, $proofData);
        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    public function mySubmissions()
    {
        $userId = $this->userId();
        $status = $this->request->get('status');
        $subs = $this->customTaskService->getMySubmissions($userId, $status, 30, 0);
        
        return view('user.custom-tasks.executor.my-submissions', [
            'submissions' => $subs,
            'statusFilter' => $status,
        ]);
    }

    /**
     * تأیید/رد توسط تبلیغ‌دهنده (Ajax)
     */
    public function review(): void
    {
        $userId = $this->userId();
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $subId = (int) ($body['submission_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $reason = $body['reason'] ?? null;

        if (!\in_array($decision, ['approve', 'reject'])) {
            $this->response->json(['success' => false, 'message' => 'تصمیم نامعتبر.'], 422);
            return;
        }

        $result = $this->customTaskService->reviewSubmission($subId, $userId, $decision, $reason);
        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * امتیازدهی به submission (Ajax)
     */
    public function rateSubmission(): void
    {
        $userId = $this->userId();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        
        $subId = (int) ($body['submission_id'] ?? 0);
        $rating = (int) ($body['rating'] ?? 0);
        $reviewText = trim($body['review_text'] ?? '');

        // Validation
        $errors = \App\Validators\CustomTaskValidator::validateRating([
            'rating' => $rating,
            'review_text' => $reviewText,
        ]);

        if (!empty($errors)) {
            $this->response->json([
                'success' => false, 
                'message' => 'خطا در اعتبارسنجی.',
                'errors' => $errors
            ], 422);
            return;
        }

        $result = $this->customTaskService->rateSubmission($subId, $userId, [
            'rating' => $rating,
            'review_text' => $reviewText,
        ]);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * افزودن/حذف از علاقه‌مندی‌ها (Ajax)
     */
    public function toggleFavorite(): void
    {
        $userId = $this->userId();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $taskId = (int) ($body['task_id'] ?? 0);

        if (!$taskId) {
            $this->response->json(['success' => false, 'message' => 'شناسه تسک الزامی است.'], 422);
            return;
        }

        $result = $this->customTaskService->toggleFavorite($taskId, $userId);
        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * لیست علاقه‌مندی‌ها
     */
    public function favorites()
    {
        $userId = $this->userId();
        
        $page = max(1, (int) $this->request->get('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $favorites = $this->customTaskService->getUserFavorites($userId, $limit, $offset);
        $total = $this->customTaskService->countUserFavorites($userId);

        return view('user.custom-tasks.favorites', [
            'favorites' => $favorites,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit),
        ]);
    }

    /**
     * داشبورد آمار (برای creator)
     */
    public function dashboard()
    {
        $userId = $this->userId();
        $dashboard = $this->analyticsService->getCreatorDashboard($userId);

        return view('user.custom-tasks.dashboard', [
            'dashboard' => $dashboard,
        ]);
    }

    /**
     * داشبورد آمار worker
     */
    public function workerDashboard()
    {
        $userId = $this->userId();
        $dashboard = $this->analyticsService->getWorkerDashboard($userId);

        return view('user.custom-tasks.executor.dashboard', [
            'dashboard' => $dashboard,
        ]);
    }

    public function reportTask(): void
    {
        $userId = $this->userId();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        
        $taskId = (int) ($body['task_id'] ?? 0);
        $reason = $body['reason'] ?? '';
        $description = trim($body['description'] ?? '');

        // Validation
        if (empty($taskId)) {
            $this->response->json(['success' => false, 'message' => 'شناسه تسک الزامی است.'], 422);
            return;
        }

        if (!in_array($reason, ['spam', 'fraud', 'inappropriate', 'misleading', 'other'])) {
            $this->response->json(['success' => false, 'message' => 'دلیل گزارش نامعتبر است.'], 422);
            return;
        }

        if (mb_strlen($description) < 20) {
            $this->response->json(['success' => false, 'message' => 'توضیحات باید حداقل 20 کاراکتر باشد.'], 422);
            return;
        }

        $result = $this->customTaskService->reportTask($taskId, $userId, $reason, $description);
        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * جزئیات و آمار یک تسک
     */
    public function analytics()
    {
        $userId = $this->userId();
        $taskId = (int) $this->request->param('id');

        // بررسی مالکیت
        $task = $this->customTaskService->find($taskId);
        if (!$task || $task->creator_id != $userId) {
            return view('errors.403');
        }

        $analytics = $this->analyticsService->getTaskStats($taskId, 30);

        return view('user.custom-tasks.analytics', [
            'task' => $task,
            'analytics' => $analytics,
        ]);
    }
}
