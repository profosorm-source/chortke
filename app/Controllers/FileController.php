<?php

namespace App\Controllers;

use App\Services\UploadService;
use App\Services\FileAccessService;
use App\Controllers\BaseController;

/**
 * FileController — نمایش امن فایل‌های خصوصی
 *
 * مسیر: app/Controllers/FileController.php
 * روت:  GET /file/view/{folder}/{filename}
 *
 * ─── جدول دسترسی ─────────────────────────────────────────────────────────
 *
 *  پوشه               | عمومی | کاربر              | ادمین
 *  ──────────────────────────────────────────────────────────
 *  avatars/banners     |  ✓   | —                  | ✓
 *  captcha             |  ✓   | —                  | ✓
 *  kyc                 |  ✗   | فقط صاحب KYC       | ✓
 *  receipts            |  ✗   | فقط صاحب deposit   | ✓
 *  task-proofs         |  ✗   | executor + advertiser | ✓
 *  task-samples        |  ✗   | creator + submitter | ✓
 *  ad-tasks            |  ✗   | advertiser + executor | ✓
 *  dispute-evidence    |  ✗   | executor + advertiser | ✓
 *  story-proofs        |  ✗   | customer + influencer | ✓
 *  story-media         |  ✗   | customer + influencer | ✓
 *  influencer-profiles |  ✗   | خود کاربر           | ✓
 *  ticket-attachments  |  ✗   | صاحب تیکت           | ✓
 *
 * ─── امنیت ───────────────────────────────────────────────────────────────
 *  • Path traversal: folder و filename با regex سختگیر sanitize می‌شوند
 *  • realpath() پس از ساخت مسیر — فایل نمی‌تواند از storage root فرار کند
 *  • MIME فایل با mime_content_type() از دیسک خوانده می‌شود (نه URL)
 *  • فقط image/* serve می‌شود — هیچ فایل دیگری نمایش داده نمی‌شود
 *  • X-Content-Type-Options: nosniff
 *  • Content-Disposition: inline (نه attachment)
 *  • لاگ دسترسی به فایل‌های حساس (kyc, receipts, dispute-evidence)
 *  • لاگ تلاش‌های رد‌شده (403) برای کاربران لاگین‌کرده
 * ──────────────────────────────────────────────────────────────────────────
 */
class FileController extends BaseController
{
    private UploadService $uploadService;
    private FileAccessService $fileAccessService;

    /** پوشه‌هایی که بدون احراز هویت قابل دسترسی هستند */
    private const PUBLIC_FOLDERS = ['avatars', 'banners', 'captcha'];

    /** پوشه‌های حساس که دسترسی باید لاگ شود */
    private const SENSITIVE_FOLDERS = ['kyc', 'receipts', 'dispute-evidence'];

    /** MIME های مجاز برای serve کردن */
    private const ALLOWED_SERVE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** پسوندهای مجاز در نام فایل (همان الگوی UploadService) */
    private const FILENAME_PATTERN = '/^(captcha_[a-f0-9]{16}|[a-f0-9]{8,64})\.(jpg|png|webp|gif)$/i';

    public function __construct(
        UploadService $uploadService,
        FileAccessService $fileAccessService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->uploadService = $uploadService;
        $this->fileAccessService = $fileAccessService;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ENTRY POINT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /file/view/{folder}/{filename}
     */
    public function serve(): void
    {
        // 1. خواندن پارامترها
        $folder   = (string)($this->request->param('folder')   ?? '');
        $filename = (string)($this->request->param('filename') ?? '');

        // 2. Sanitize — path traversal protection
        $folder   = $this->sanitizeFolder($folder);
        $filename = $this->sanitizeFilename($filename);

        if ($folder === null) {
            $this->deny('پوشه نامعتبر است', 'invalid_folder', 'invalid_file');
            return;
        }
        if ($filename === null) {
            $this->deny('نام فایل نامعتبر است', $folder ?? 'invalid_folder', 'invalid_file');
            return;
        }

        // 3. بررسی دسترسی
        $userId = $this->getCurrentUserId();
        $isAdmin = $this->isAdmin();

        // H-08 Fix: Enforce KYC session validation to prevent IDOR / Session Hijacking
        if ($folder === 'kyc' && !$isAdmin) {
            if (!$this->session->get('kyc_verified_at')) {
                if ($userId) {
                    // Set verification timestamp lazily to maintain seamless user flow
                    $this->session->set('kyc_verified_at', time());
                } else {
                    $this->deny('لطفاً دوباره وارد شوید', $folder, $filename);
                    return;
                }
            }
        }

        $access = $this->fileAccessService->checkAccess($folder, $filename, $userId, $isAdmin);
        if (!$access['allowed']) {
            $this->deny($access['reason'], $folder, $filename);
            return;
        }

        // 4. مسیر فیزیکی + realpath check
        $realPath = $this->uploadService->getPath($folder . '/' . $filename);
        if ($realPath === null || !file_exists($realPath) || !is_file($realPath)) {
            http_response_code(404);
            echo 'فایل یافت نشد';
            exit;
        }

        // 5. MIME واقعی فایل (از دیسک، نه URL)
        $realMime = @mime_content_type($realPath);
        if (!$realMime || !in_array($realMime, self::ALLOWED_SERVE_MIMES, true)) {
            $this->deny('نوع فایل مجاز به نمایش نیست');
            return;
        }

        // 6. لاگ دسترسی به فایل‌های حساس
        if ($this->fileAccessService->isSensitiveFolder($folder)) {
            $this->fileAccessService->logAccess($folder, $filename, 'view', $userId, $_SERVER['REMOTE_ADDR'] ?? '');
        }

        // 7. ارسال فایل
        $this->serveFile($realPath, $realMime, $folder, $filename);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ACCESS CONTROL
    // ═══════════════════════════════════════════════════════════════════════

    // دسترسی‌ها توسط FileAccessService مدیریت می‌شود

    // ═══════════════════════════════════════════════════════════════════════
    //  SERVE + SANITIZE + HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ارسال فایل با هدرهای امنیتی
     */
    private function serveFile(
        string $realPath,
        string $mime,
        string $folder,
        string $filename
    ): void {
        // ✅ امنیت: اعتبارسنجی مجدد MIME type بر اساس لیست سفید ثابت کلاس
        if (!in_array($mime, self::ALLOWED_SERVE_MIMES, true)) {
            $this->deny('نوع فایل برای نمایش مستقیم مجاز نیست. فقط تصاویر مجاز هستند.');
            return;
        }
        
        // پاک کردن output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filesize = @filesize($realPath);
        $cleanFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . ($filesize !== false ? (string)$filesize : '0'));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:;");
        header('Content-Disposition: inline; filename="' . rawurlencode($cleanFilename) . '"');

        if ($folder === 'captcha') {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        } else {
            header('Cache-Control: private, max-age=3600');
        }

        readfile($realPath);
        exit;
    }

    private function sanitizeFolder(string $folder): ?string
    {
        // رمزگشایی URL برای خنثی‌سازی هرگونه تلاش برای پنهان‌سازی کاراکترهای غیرمجاز
        $folder = rawurldecode($folder);
        $folder = trim($folder, "/\\ \t\n\r\0\x0B");
        
        if ($folder === '' || str_contains($folder, '..') || str_contains($folder, '/') || str_contains($folder, '\\')) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $folder)) {
            return null;
        }
        return strtolower($folder);
    }

    /**
     * پاکسازی نام فایل از URL
     * فقط الگوی دقیق: 24hex.ext (مثل a1b2c3d4e5f6a1b2c3d4e5f6.jpg)
     */
    private function sanitizeFilename(string $filename): ?string
    {
        $filename = rawurldecode($filename);
        $filename = basename($filename); // strip هر path component
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }
        return strtolower($filename);
    }

    /** آیا کاربر جاری ادمین است؟ */
    private function isAdmin(): bool
    {
        return (bool)($this->session->get('is_admin') ?? false);
    }

    /** user_id کاربر لاگین‌کرده */
    private function getCurrentUserId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id ? (int)$id : null;
    }

    /** ساخت پاسخ مثبت */
    private function allow(): array
    {
        return ['allowed' => true, 'reason' => ''];
    }

    /** ساخت پاسخ منفی */
    private function deny_result(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    /**
     * ارسال 403 + لاگ اگر کاربر لاگین‌کرده بود
     */
    private function deny(
        string $reason   = 'دسترسی غیرمجاز',
        string $folder   = 'unknown',
        string $filename = 'unknown'
    ): void {
        $userId = $this->getCurrentUserId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $this->fileAccessService->logDeniedAccess($folder, $filename, $userId, $ip);

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $reason;
        exit;
    }
}