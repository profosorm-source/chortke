<?php
// app/Controllers/User/ContentController.php

namespace App\Controllers\User;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Services\ContentService;
use App\Controllers\User\BaseUserController;
use Core\Exceptions\NotFoundException;
use Core\Exceptions\UnauthorizedException;
use Psr\Log\LoggerInterface;

/**
 * کنترلر مدیریت محتوا
 * 
 * @package App\Controllers\User
 */
class ContentController extends BaseUserController
{
    private const ITEMS_PER_PAGE = 10;
    private const REVENUES_PER_PAGE = 15;
    
    private ContentService $contentService;
    private ContentSubmission $contentSubmissionModel;
    private ContentRevenue $contentRevenueModel;
    private ?LoggerInterface $logger;
    private \Core\CSRF $csrf;

    public function __construct(
        ContentRevenue $contentRevenueModel,
        ContentSubmission $contentSubmissionModel,
        ContentService $contentService,
        \Core\CSRF $csrf,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->contentRevenueModel = $contentRevenueModel;
        $this->contentSubmissionModel = $contentSubmissionModel;
        $this->contentService = $contentService;
        $this->csrf = $csrf;
        $this->logger = $logger;
    }

    /**
     * صفحه لیست محتواهای کاربر
     * 
     * @return string HTML view
     */
    public function index(): string
    {
        try {
            $this->requireAuth();
            $user = $this->userService->find($this->userId());

            // 🛡️ MUST HAVE KYC VERIFIED
            if (($user->kyc_status ?? '') !== 'verified') {
                $this->session->setFlash('error', 'جهت فعالیت در سیستم تولید محتوا، احراز هویت حساب شما باید تکمیل و تایید شده باشد.');
                redirect(url('/profile/kyc'));
                return '';
            }

            $userId = user_id();
            
            $status = $this->sanitizeStatus($this->request->get('status'));
            $page = $this->sanitizePage($this->request->get('page'));
            $offset = ($page - 1) * self::ITEMS_PER_PAGE;

            // بهینه‌سازی: دریافت تمام داده‌ها با یک query
            $data = $this->contentSubmissionModel->getUserContentData(
                $userId, 
                $status, 
                self::ITEMS_PER_PAGE, 
                $offset
            );

            // MED-06 Fix: Removed redundant user find call

            return view('user.content.index', [
                'user' => $user,
                'submissions' => $data['submissions'],
                'stats' => $data['stats'],
                'totalRevenue' => $data['totalRevenue'],
                'pendingRevenue' => $data['pendingRevenue'],
                'total' => $data['total'],
                'totalPages' => $data['totalPages'],
                'currentPage' => $page,
                'currentStatus' => $status,
            ]);
            
        } catch (\Throwable $e) {
            $this->logError('Error in content index', $e);
            return view('errors.500');
        }
    }

    /**
     * صفحه ارسال محتوای جدید
     * 
     * @return string HTML view
     */
    public function create(): string
    {
        try {
            $this->requireAuth();
            $user = $this->userService->find($this->userId());

            // 🛡️ MUST HAVE KYC VERIFIED
            if (($user->kyc_status ?? '') !== 'verified') {
                $this->session->setFlash('error', 'جهت ثبت محتوای جدید ابتدا باید احراز هویت خود را تکمیل کنید.');
                redirect(url('/profile/kyc'));
                return '';
            }

            return view('user.content.create', [
                'user' => $user,
                'agreementText' => $this->contentService->getAgreementText(),
                'settings' => $this->contentService->getSettings(),
            ]);
            
        } catch (\Throwable $e) {
            $this->logError('Error in content create', $e);
            return view('errors.500');
        }
    }

    /**
     * ثبت محتوای جدید (POST)
     * 
     * @return array JSON response
     */
    public function store(): array
    {
        // خواندن و sanitize داده‌ها
        $input = $this->getJsonInput();
        
        // Validate CSRF Token
        if (!$this->validateCsrfToken()) {
            throw new \Core\Exceptions\SecurityException('توکن امنیتی نامعتبر است.');
        }

        // اعتبارسنجی
        $validator = $this->validateStoreInput($input);

        if ($validator->fails()) {
            throw new \Core\Exceptions\ValidationException($validator->errors());
        }

        $data = $validator->data();
        
        // Sanitize input fields to prevent HTML Injection / Stored XSS (H-04)
        $data['title'] = strip_tags((string)$data['title']);
        $data['description'] = strip_tags((string)($data['description'] ?? ''), '<br><p><strong><em>');
        $data['video_url'] = trim((string)$data['video_url']);
        
        // Submit content (Throws BusinessException on failure)
        $result = $this->contentService->submitContent(user_id(), $data);

        return $this->response->json($result, 200);
    }

    /**
     * مشاهده جزئیات یک محتوا
     * 
     * @return string HTML view
     * @throws NotFoundException
     */
    public function show(): string
    {
        try {
            $id = $this->sanitizeId($this->request->param('id'));
            $userId = user_id();

            $submission = $this->contentSubmissionModel->find($id);

            if (!$submission || $submission->user_id !== $userId) {
                throw new NotFoundException('محتوای مورد نظر یافت نشد.');
            }

            // درآمدهای این محتوا
            $revenues = $this->contentRevenueModel->getBySubmission($id);
            $user = $this->userService->find($this->userId());

            return view('user.content.show', [
                'user' => $user,
                'submission' => $submission,
                'revenues' => $revenues,
            ]);
            
        } catch (NotFoundException $e) {
            return view('errors.404');
        } catch (\Throwable $e) {
            $this->logError('Error in content show', $e);
            return view('errors.500');
        }
    }

    /**
     * لیست درآمدها
     * 
     * @return string HTML view
     */
    public function revenues(): string
    {
        try {
            $userId = user_id();
            $page = $this->sanitizePage($this->request->get('page'));
            $offset = ($page - 1) * self::REVENUES_PER_PAGE;

            $revenues = $this->contentRevenueModel->getByUser(
                $userId, 
                self::REVENUES_PER_PAGE, 
                $offset
            );
            
            $total = $this->contentRevenueModel->countByUser($userId);
            $totalPages = (int)ceil($total / self::REVENUES_PER_PAGE);

            // استفاده از cache برای آمار
            $totalPaid = $this->contentRevenueModel->getTotalUserRevenue(
                $userId, 
                ContentRevenue::STATUS_PAID
            );
            $totalPending = $this->contentRevenueModel->getTotalUserRevenue(
                $userId, 
                ContentRevenue::STATUS_PENDING
            );

            $user = $this->userService->find($this->userId());

            return view('user.content.revenues', [
                'user' => $user,
                'revenues' => $revenues,
                'totalPaid' => $totalPaid,
                'totalPending' => $totalPending,
                'total' => $total,
                'totalPages' => $totalPages,
                'currentPage' => $page,
            ]);
            
        } catch (\Throwable $e) {
            $this->logError('Error in content revenues', $e);
            return view('errors.500');
        }
    }

    /**
     * دریافت ورودی JSON به صورت امن
     * 
     * @return array
     */
    private function getJsonInput(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!is_array($input)) {
            $input = $this->request->body();
        }
        
        return is_array($input) ? $input : [];
    }

    /**
     * اعتبارسنجی ورودی فرم ثبت محتوا
     * 
     * @param array $input
     * @return \Core\Validator
     */
    private function validateStoreInput(array $input): \Core\Validator
    {
        return $this->validatorFactory()->make($input, [
            'platform' => 'required|in:aparat,youtube,upload_center',
            'video_url' => 'required|url|max:500',
            'title' => 'required|min:5|max:255',
            'description' => 'max:2000',
            'category' => 'max:100',
            'agreement_accepted' => 'required|accepted',
        ], [
            'platform.required' => 'انتخاب پلتفرم الزامی است.',
            'platform.in' => 'پلتفرم انتخابی نامعتبر است.',
            'video_url.required' => 'لینک ویدیو الزامی است.',
            'video_url.url' => 'فرمت لینک ویدیو نامعتبر است.',
            'video_url.max' => 'لینک ویدیو بیش از حد طولانی است.',
            'title.required' => 'عنوان ویدیو الزامی است.',
            'title.min' => 'عنوان باید حداقل 5 کاراکتر باشد.',
            'title.max' => 'عنوان نباید بیشتر از 255 کاراکتر باشد.',
            'description.max' => 'توضیحات نباید بیشتر از 2000 کاراکتر باشد.',
            'agreement_accepted.required' => 'پذیرش تعهدنامه الزامی است.',
        ]);
    }

    /**
     * Sanitize وضعیت
     * 
     * @param mixed $status
     * @return string|null
     */
    private function sanitizeStatus($status): ?string
    {
        if (!is_string($status)) {
            return null;
        }
        
        $allowedStatuses = ContentSubmission::ALLOWED_STATUSES;
        return in_array($status, $allowedStatuses, true) ? $status : null;
    }

    /**
     * Sanitize شماره صفحه
     * 
     * @param mixed $page
     * @return int
     */
    private function sanitizePage($page): int
    {
        $page = filter_var($page, FILTER_VALIDATE_INT);
        return max(1, $page ?: 1);
    }

    /**
     * Sanitize شناسه
     * 
     * @param mixed $id
     * @return int
     */
    private function sanitizeId($id): int
    {
        return (int)filter_var($id, FILTER_VALIDATE_INT) ?: 0;
    }

    /**
     * بررسی CSRF Token
     * 
     * @return bool
     */
    private function validateCsrfToken(): bool
    {
        // M16 Fix: همسان‌سازی اعتبارسنجی برای پشتیبانی همزمان از هدر استاندارد و هدر کلاینت‌های Axios/Vue
        $token = $this->request->header('X-CSRF-TOKEN') ?? $this->request->header('X-XSRF-TOKEN');
        
        if (!$token) {
            return false;
        }
        
        return $this->csrf->verify($token);
    }

    /**
     * خروجی JSON خطا
     * 
     * @param string $message
     * @param int $code
     * @param array|null $errors
     * @return array
     */
    private function jsonError(
        string $message, 
        int $code = 400, 
        ?array $errors = null
    ): array {
        $response = [
            'success' => false,
            'message' => $message,
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        return $this->response->json($response, $code);
    }

    /**
     * لاگ خطا
     * 
     * @param string $message
     * @param \Throwable $e
     * @return void
     */
    private function logError(string $message, \Throwable $e): void
{
    $context = [
        'channel' => 'content',
        'message' => $message,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'user_id' => function_exists('user_id') ? user_id() : null,
    ];

    if ($this->logger) {
        $this->logger->error('content.error', $context);
    } else {
        $this->logger->error('content.error', $context);
    }
}
}
