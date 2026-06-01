<?php

namespace App\Controllers\User;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Services\TicketService;
use App\Services\UploadService;
use App\Controllers\User\BaseUserController;

class BugReportController extends BaseUserController
{
    private TicketService $ticketService;
    private UploadService $uploadService;

    public function __construct(
        TicketService $ticketService,
        UploadService $uploadService
    , ?\App\Contracts\LoggerInterface $logger = null)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->ticketService = $ticketService;
        $this->uploadService = $uploadService;
    }

    public function store(): void
    {
        // 🛡️ CRITICAL-10: بررسی هویت کاربر باید پیش از بررسی توکن CSRF رخ دهد
        $this->requireAuth();
        $this->validateCsrf();
        
        $userId = $this->userId();

        // H-08: Spam Flood Protection
        try {
            rate_limit('social', 'message', "bug_report_user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->response->json(['success' => false, 'message' => $e->getMessage()], 429);
                return;
            }
        }

        $screenRes = $this->request->post('screen_resolution') ?? '';
        if ($screenRes && !preg_match('/^\d{1,5}x\d{1,5}$/', $screenRes)) {
            $screenRes = '';
        }

        // 🛡️ HIGH-10: ترکیب متغیرهای کلاینت با فاکتورهای سروری جهت جلوگیری از جعل هویت مرورگر
        $clientFingerprint = $this->request->post('device_fingerprint') ?? '';
        $ip = $this->request->ip();
        $ua = $this->request->header('User-Agent') ?? '';
        $fingerprint = hash('sha256', $clientFingerprint . '_' . $ip . '_' . $ua);

        // 🛡️ Item 23: Category Whitelisting
        $category = $this->request->post('category') ?: 'other';
        $allowedCategories = ['ui', 'functional', 'performance', 'security', 'other'];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'other';
        }

        // 🛡️ HIGH-09: اعتبارسنجی سختگیرانه URL با بررسی پروتکل‌های معتبر جهت دفع حملات Phishing
        $pageUrl = (string)$this->request->post('page_url');
        if (!empty($pageUrl)) {
            $sanitizedUrl = filter_var($pageUrl, FILTER_SANITIZE_URL);
            if (!filter_var($sanitizedUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $sanitizedUrl)) {
                $this->response->json(['success' => false, 'message' => 'آدرس صفحه نامعتبر است.'], 422);
                return;
            }
            
            // ✅ اطمینان از اینکه URL در دامنه خود سایت است
            $parsedUrl = parse_url($sanitizedUrl);
            $host = preg_replace('/^www\./', '', strtolower($parsedUrl['host'] ?? ''));
            $serverHost = preg_replace('/^www\./', '', strtolower($_SERVER['HTTP_HOST'] ?? ''));
            if ($host && $serverHost && $host !== $serverHost) {
                $this->response->json(['success' => false, 'message' => 'آدرس صفحه باید در دامنه سایت باشد'], 422);
                return;
            }
            
            $pageUrl = $sanitizedUrl;
        } else {
            $pageUrl = '';
        }

        // C-05: Input Sanitization (XSS Protection)
        $data = [
            'page_url'           => $pageUrl,
            'page_title'         => htmlspecialchars($this->request->post('page_title') ?? '', ENT_QUOTES, 'UTF-8'),
            'category'           => htmlspecialchars($category, ENT_QUOTES, 'UTF-8'),
            'description'        => htmlspecialchars($this->request->post('description') ?? '', ENT_QUOTES, 'UTF-8'),
            'screen_resolution'  => htmlspecialchars($screenRes, ENT_QUOTES, 'UTF-8'),
            'device_fingerprint' => htmlspecialchars($fingerprint, ENT_QUOTES, 'UTF-8'),
            'user_agent'         => htmlspecialchars(substr($ua, 0, 512), ENT_QUOTES, 'UTF-8'), // 🛡️ LOW-03: فرار دادن کامل هدر User-Agent
            'ip_address'         => $ip,
        ];

        // 🛡️ Item 13: Screenshot path traversal check and Request wrapper usage
        $screenshotFile = $this->request->file('screenshot');
        if ($screenshotFile && $screenshotFile['error'] !== UPLOAD_ERR_NO_FILE) {
            $filename = basename($screenshotFile['name']);
            if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
                $this->response->json(['success' => false, 'message' => 'نام فایل نامعتبر است.'], 400);
                return;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'png', 'jpeg'], true)) {
                $this->response->json(['success' => false, 'message' => 'نوع فایل مجاز نیست.'], 400);
                return;
            }

            // 🛡️ HIGH-10: بررسی بایت‌های جادویی (Magic Bytes) فایل جهت ممانعت از آپلود فایل‌های مخرب
            $tmpPath = $screenshotFile['tmp_name'] ?? '';
            if ($tmpPath && file_exists($tmpPath)) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
                    $this->response->json(['success' => false, 'message' => 'محتوای فایل اسکرین‌شات نامعتبر است.'], 400);
                    return;
                }
            }

            $uploadResult = $this->uploadService->upload(
                $screenshotFile,
                'bug-reports',
                ['image/jpeg', 'image/png'],
                5 * 1024 * 1024
            );

            // 🛡️ MED-11: بازگرداندن خطای مناسب به جای عبور بی‌صدا در زمان شکست عملیات آپلود اسکرین‌شات
            if ($uploadResult['success']) {
                $data['screenshot'] = htmlspecialchars($uploadResult['path'], ENT_QUOTES, 'UTF-8');
            } else {
                $this->logger->warning('bug_report.screenshot.failed', [
                    'user_id' => $userId,
                    'error' => $uploadResult['message'] ?? 'Unknown upload error'
                ]);
                $this->response->json([
                    'success' => false,
                    'message' => 'آپلود اسکرین‌شات ناموفق بود: ' . ($uploadResult['message'] ?? 'خطای ناشناخته')
                ], 400);
                return;
            }
        }

        $result = $this->ticketService->submitBugReport($userId, $data, $this->uploadService);

        if ($result['success']) {
            $result['message'] = 'گزارش شما با موفقیت در سیستم ثبت شد.';
        }

        $this->response->json($result);
    }

    /**
     * لیست گزارش‌های کاربر
     */
    public function index()
    {
        $this->requireAuth();
        $userId = $this->userId();

        $page = (int)($this->request->get('page') ?: 1);
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $reports = $this->ticketService->getBugReports($userId, $perPage, $offset);

        return view('user.bug-reports.index', [
            'reports' => $reports,
            'page' => $page,
        ]);
    }

    /**
     * جزئیات گزارش
     */
    public function show()
    {
        $this->requireAuth();
        $userId = $this->userId();
        $id = (int)$this->request->param('id');

        $service = $this->ticketService;
        $report  = $service->findBugReport($id);
        if (!$report || (int)$report->user_id !== $userId) {
            $this->session->setFlash('error', 'گزارش یافت نشد');
            return redirect(url('/bug-reports'));
        }

        $comments = $service->getBugReportComments($id);

        return view('user.bug-reports.show', [
            'report' => $report,
            'comments' => $comments,
        ]);
    }

    public function addComment(): void
    {
        // 🛡️ CRITICAL-10: بررسی هویت کاربر باید پیش از بررسی توکن CSRF رخ دهد
        $this->requireAuth();
        $this->validateCsrf();
        
        $userId = $this->userId();
        $id = (int)$this->request->param('id');

        // C-03: Ownership Verification (IDOR Protection)
        $report = $this->ticketService->findBugReport($id);
        if (!$report || (int)$report->user_id !== $userId) {
            $this->response->json(['success' => false, 'message' => 'گزارش یافت نشد یا دسترسی غیرمجاز است'], 403);
            return;
        }

        $data = $this->request->json() ?? [];
        $comment = trim($data['comment'] ?? '');

        if (empty($comment)) {
            $this->response->json(['success' => false, 'message' => 'متن نظر نمی‌تواند خالی باشد'], 422);
            return;
        }

        if (mb_strlen($comment) > 2000) {
            $this->response->json(['success' => false, 'message' => 'کامنت بیش از حد طولانی است'], 422);
            return;
        }

        // 🛡️ CRIT-05: ضدعفونی پیام نظر گزارش باگ جهت ممانعت از حملات XSS قبل از ارسال به لایه سرویس
        $comment = htmlspecialchars($comment, ENT_QUOTES, 'UTF-8');

        $result = $this->ticketService->reply($id, $userId, $comment, false);

        $this->response->json($result);
    }
}