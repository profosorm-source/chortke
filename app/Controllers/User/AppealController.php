<?php

declare(strict_types=1);

namespace App\Controllers\User;

use Core\Request;
use Core\Response;
use Core\Session;
use App\Services\Shared\DisputeService;
use App\Services\UploadService;

/**
 * AppealController - کنترلر اعتراضات کاربران
 */
class AppealController extends BaseUserController
{
    private DisputeService $appealService;
    private UploadService $uploadService;

    public function __construct(DisputeService $appealService, UploadService $uploadService)
    {
        parent::__construct();
        $this->appealService = $appealService;
        $this->uploadService = $uploadService;
    }

    /**
     * نمایش فرم ارسال اعتراض
     */
    public function showSubmitForm(Request $request): Response
    {
        $userId = (int) $this->session->get('user_id');
        $appealType = $request->get('type');

        // گرفتن قالب‌های اعتراض
        $templates = $this->appealService->getAppealTemplates($appealType);

        // بررسی محدودیت‌ها
        $canSubmit = $this->appealService->canSubmitAppeal($userId);

        return Response::view('user/appeal/submit', [
            'appealType' => $appealType,
            'templates' => $templates,
            'canSubmit' => $canSubmit
        ]);
    }

    /**
     * ارسال اعتراض
     */
    public function submit(Request $request): Response
    {
        $userId = (int) $this->session->get('user_id');

        $appealType = $request->post('appeal_type');
        $title = trim($request->post('title'));
        $description = trim($request->post('description'));
        $referenceId = $request->post('reference_id') ? (int) $request->post('reference_id') : null;
        $referenceType = $request->post('reference_type');

        // اعتبارسنجی
        if (!$appealType || !$title || !$description) {
            return Response::json([
                'success' => false,
                'message' => 'All fields are required'
            ], 400);
        }

        if (strlen($title) < 10 || strlen($description) < 50) {
            return Response::json([
                'success' => false,
                'message' => 'Title and description must be detailed enough'
            ], 400);
        }

        // بررسی محدودیت ارسال
        if (!$this->appealService->canSubmitAppeal($userId)) {
            return Response::json([
                'success' => false,
                'message' => 'Appeal submission limit exceeded. Please try again later.'
            ], 429);
        }

        try {
            // پردازش پیوست‌ها
            $attachments = $this->processAttachments($request, $userId);

            // ارسال اعتراض
            $result = $this->appealService->submitAppeal(
                $userId,
                $appealType,
                $title,
                $description,
                $referenceId,
                $referenceType,
                $attachments
            );

            return Response::json([
                'success' => true,
                'message' => 'Appeal submitted successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to submit appeal: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * لیست اعتراضات کاربر
     */
    public function index(Request $request): Response
    {
        $userId = (int) $this->session->get('user_id');
        $page = (int) ($request->get('page') ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            $appeals = $this->appealService->getUserAppeals($userId, $limit, $offset);

            return Response::view('user/appeal/index', [
                'appeals' => $appeals,
                'currentPage' => $page,
                'hasMore' => count($appeals) === $limit
            ]);

        } catch (\Exception $e) {
            return Response::view('error/500', [
                'message' => 'Failed to load appeals'
            ], 500);
        }
    }

    /**
     * نمایش جزئیات اعتراض
     */
    public function show(Request $request, int $appealId): Response
    {
        $userId = (int) $this->session->get('user_id');

        try {
            $details = $this->appealService->getAppealDetails($appealId, $userId);

            if (!$details) {
                return Response::view('error/404', [
                    'message' => 'Appeal not found'
                ], 404);
            }

            return Response::view('user/appeal/show', [
                'details' => $details
            ]);

        } catch (\Exception $e) {
            return Response::view('error/500', [
                'message' => 'Failed to load appeal details'
            ], 500);
        }
    }

    /**
     * گرفتن قالب اعتراض
     */
    public function getTemplate(Request $request): Response
    {
        $appealType = $request->get('type');

        if (!$appealType) {
            return Response::json([
                'success' => false,
                'message' => 'Appeal type is required'
            ], 400);
        }

        try {
            $templates = $this->appealService->getAppealTemplates($appealType);

            return Response::json([
                'success' => true,
                'templates' => $templates
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to load template'
            ], 500);
        }
    }

    /**
     * بررسی وضعیت ارسال اعتراض
     */
    public function checkSubmissionStatus(Request $request): Response
    {
        $userId = (int) $this->session->get('user_id');

        try {
            $canSubmit = $this->appealService->canSubmitAppeal($userId);

            return Response::json([
                'success' => true,
                'data' => [
                    'can_submit' => $canSubmit
                ]
            ]);

        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to check submission status'
            ], 500);
        }
    }

    /**
     * پردازش پیوست‌های آپلود شده
     */
    private function processAttachments(Request $request, int $userId): array
    {
        $attachments = [];

        if (!isset($_FILES['attachments'])) {
            return $attachments;
        }

        $files = $_FILES['attachments'];

        // تبدیل به آرایه اگر یک فایل باشد
        if (!is_array($files['name'])) {
            $files = [
                'name' => [$files['name']],
                'type' => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'error' => [$files['error']],
                'size' => [$files['size']]
            ];
        }

        $maxFiles = 5;
        $maxSize = 10 * 1024 * 1024; // 10MB
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];

        for ($i = 0; $i < min(count($files['name']), $maxFiles); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }

            if ($files['size'][$i] > $maxSize) {
                continue;
            }

            if (!in_array($files['type'][$i], $allowedTypes)) {
                continue;
            }

            // آپلود فایل
            $uploadResult = $this->uploadService->uploadFile(
                $files['tmp_name'][$i],
                $files['name'][$i],
                'appeals/',
                $userId
            );

            if ($uploadResult['success']) {
                $attachments[] = [
                    'filename' => $uploadResult['filename'],
                    'original_name' => $files['name'][$i],
                    'file_path' => $uploadResult['path'],
                    'file_size' => $files['size'][$i],
                    'mime_type' => $files['type'][$i]
                ];
            }
        }

        return $attachments;
    }
}