<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\Notification;
use App\Services\UploadService;

class BugReportService extends \App\Services\BaseService
{
    private UploadService $uploadService;
    private Notification $notificationModel;
    private BugReportComment $bugReportCommentModel;
    private Database $db;
    private BugReport $bugReportModel;

    private const MAX_DAILY_REPORTS = 2;
    private const SUSPICIOUS_CONSECUTIVE_DAYS = 5;

    public function __construct(
        LoggerInterface $logger,
        Database $db,
        BugReport $bugReportModel,
        BugReportComment $bugReportCommentModel,
        Notification $notificationModel,
        UploadService $uploadService
    ) {
        parent::__construct($logger);
        $this->db = $db;
        $this->bugReportModel = $bugReportModel;
        $this->bugReportCommentModel = $bugReportCommentModel;
        $this->notificationModel = $notificationModel;
        $this->uploadService = $uploadService;
    }

    /**
     * ثبت گزارش باگ توسط کاربر
     */
    public function submitReport(int $userId, array $data): array
    {
        // بررسی محدودیت روزانه
        $todayCount = (int)$this->bugReportModel->countTodayByUser($userId);
        if ($todayCount >= self::MAX_DAILY_REPORTS) {
            return [
                'success' => false,
                'message' => 'شما امروز حداکثر تعداد گزارش مجاز (' . self::MAX_DAILY_REPORTS . ' بار) را ثبت کرده‌اید.'
            ];
        }

        // اعتبارسنجی
        $errors = $this->validateReport($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // بررسی مشکوک بودن (گزارش مداوم روزانه)
        $consecutiveDays = (int)$this->bugReportModel->countConsecutiveDays($userId, self::SUSPICIOUS_CONSECUTIVE_DAYS);
        $isSuspicious = ($consecutiveDays >= self::SUSPICIOUS_CONSECUTIVE_DAYS - 1); // اگر 4 روز متوالی قبلش هم ارسال کرده

        // تشخیص مرورگر و سیستم‌عامل
        $userAgent = $data['user_agent'] ?? null;
        $browserInfo = $this->parseBrowser($userAgent);

        // آپلود اسکرین‌شات
        $screenshotPath = null;
        if (isset($data['screenshot']) && $data['screenshot']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadScreenshot($data['screenshot']);
            if ($uploadResult['success']) {
                $screenshotPath = $uploadResult['path'];
            }
        }

        // تعیین اولویت خودکار
        $priority = $this->detectPriority($data);

        $reportData = [
            'user_id' => $userId,
            'page_url' => \mb_substr((string)($data['page_url'] ?? ''), 0, 500),
            'page_title' => \mb_substr((string)($data['page_title'] ?? ''), 0, 255),
            'category' => $data['category'] ?? 'other',
            'priority' => $priority,
            'description' => $data['description'],
            'screenshot_path' => $screenshotPath,
            'status' => 'open',
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $userAgent ? \mb_substr($userAgent, 0, 500) : null,
            'device_fingerprint' => $data['device_fingerprint'] ?? null,
            'browser' => $browserInfo['browser'] ?? null,
            'os' => $browserInfo['os'] ?? null,
            'screen_resolution' => $data['screen_resolution'] ?? null,
            'daily_report_count' => $todayCount + 1,
            'is_suspicious' => (int)$isSuspicious,
        ];

        $id = $this->bugReportModel->create($reportData);

        if (!$id) {
            return ['success' => false, 'message' => 'خطا در ثبت گزارش'];
        }

        // لاگ
        $level = $isSuspicious ? 'warning' : 'info';
        $this->logger->log(
            $level,
            'bug_report_submitted',
            ['message' => "گزارش باگ #{$id} توسط کاربر {$userId} ثبت شد" . ($isSuspicious ? ' [مشکوک]' : '')]
        );

        // نوتیفیکیشن به ادمین‌ها (اگر بحرانی یا امنیتی)
        if ($priority === 'critical' || ($data['category'] ?? '') === 'security') {
            $this->notifyAdmins((int)$id, $priority, (string)($data['category'] ?? ''));
        }

        return [
            'success' => true,
            'report_id' => $id,
            'message' => 'گزارش شما با موفقیت ثبت شد. از همکاری شما متشکریم!',
            'is_suspicious' => $isSuspicious,
        ];
    }

    /**
     * تغییر وضعیت گزارش (ادمین)
     */
    public function updateStatus(int $id, string $status, int $adminId, ?string $note = null): array
    {
        $report = $this->bugReportModel->find($id);
        if (!$report) {
            return ['success' => false, 'message' => 'گزارش یافت نشد'];
        }

        $validStatuses = ['open', 'in_progress', 'resolved', 'closed', 'duplicate', 'wont_fix'];
        if (!\in_array($status, $validStatuses, true)) {
            return ['success' => false, 'message' => 'وضعیت نامعتبر'];
        }

        $updateData = ['status' => $status];

        if ($note !== null) {
            $updateData['admin_note'] = $note;
        }

        if ($status === 'in_progress') {
            $updateData['assigned_to'] = $adminId;
        }

        if (\in_array($status, ['resolved', 'closed'], true)) {
            $updateData['resolved_by'] = $adminId;
            $updateData['resolved_at'] = \date('Y-m-d H:i:s');
        }

        $this->bugReportModel->update($id, $updateData);

        // ثبت کامنت تغییر وضعیت
        $statusLabels = [
            'open' => 'باز', 'in_progress' => 'در حال بررسی',
            'resolved' => 'حل شده', 'closed' => 'بسته شده',
            'duplicate' => 'تکراری', 'wont_fix' => 'رد شده'
        ];

        $this->bugReportCommentModel->create([
            'bug_report_id' => $id,
            'user_id' => $adminId,
            'user_type' => 'admin',
            'comment' => 'وضعیت به «' . ($statusLabels[$status] ?? $status) . '» تغییر یافت.' . ($note ? "\n" . $note : ''),
            'is_internal' => 0,
        ]);

        // نوتیفیکیشن به کاربر
        $this->notificationModel->create([
            'user_id' => $report->user_id,
            'type' => 'bug_report_update',
            'title' => 'بروزرسانی گزارش باگ',
            'message' => "وضعیت گزارش #{$id} به «{$statusLabels[$status]}» تغییر یافت.",
            'link' => "/bug-reports/{$id}",
        ]);

        $this->logger->info('bug_report_status_changed', ['message' => "وضعیت گزارش #{$id} به {$status} تغییر یافت توسط ادمین {$adminId}"]);

        return ['success' => true, 'message' => 'وضعیت با موفقیت تغییر یافت'];
    }

    /**
     * تغییر اولویت (ادمین)
     */
    public function updatePriority(int $id, string $priority, int $adminId): array
    {
        $report = $this->bugReportModel->find($id);
        if (!$report) {
            return ['success' => false, 'message' => 'گزارش یافت نشد'];
        }

        $validPriorities = ['low', 'normal', 'high', 'critical'];
        if (!\in_array($priority, $validPriorities, true)) {
            return ['success' => false, 'message' => 'اولویت نامعتبر'];
        }

        $this->bugReportModel->update($id, ['priority' => $priority]);

        $this->logger->info('bug_report_priority_changed', ['message' => "اولویت گزارش #{$id} به {$priority} تغییر یافت"]);

        return ['success' => true, 'message' => 'اولویت با موفقیت تغییر یافت'];
    }

    /**
     * افزودن کامنت
     */
    public function addComment(int $reportId, int $userId, string $userType, string $comment, bool $isInternal = false, ?array $attachment = null): array
    {
        $report = $this->bugReportModel->find($reportId);
        if (!$report) {
            return ['success' => false, 'message' => 'گزارش یافت نشد'];
        }

        if (empty(\trim($comment))) {
            return ['success' => false, 'message' => 'متن پیام نمی‌تواند خالی باشد'];
        }

        // اگر کاربر عادی باشد، فقط کامنت‌های عمومی مجاز است
        if ($userType === 'user') {
            $isInternal = false;

            // فقط صاحب گزارش می‌تواند کامنت بگذارد
            if ($report->user_id !== $userId) {
                return ['success' => false, 'message' => 'دسترسی غیرمجاز'];
            }
        }

        $attachmentPath = null;
        if ($attachment && $attachment['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadScreenshot($attachment);
            if ($uploadResult['success']) {
                $attachmentPath = $uploadResult['path'];
            }
        }

        $commentId = $this->bugReportCommentModel->create([
            'bug_report_id' => $reportId,
            'user_id' => $userId,
            'user_type' => $userType,
            'comment' => $comment,
            'attachment_path' => $attachmentPath,
            'is_internal' => (int)$isInternal,
        ]);

        if (!$commentId) {
            return ['success' => false, 'message' => 'خطا در ثبت پیام'];
        }

        // نوتیفیکیشن
        if ($userType === 'admin' && !$isInternal) {
            $this->notificationModel->create([
                'user_id' => $report->user_id,
                'type' => 'bug_report_comment',
                'title' => 'پاسخ جدید به گزارش باگ',
                'message' => "پاسخ جدیدی برای گزارش #{$reportId} ثبت شد.",
                'link' => "/bug-reports/{$reportId}",
            ]);
        }

        return ['success' => true, 'message' => 'پیام با موفقیت ثبت شد'];
    }

    /**
     * علامت‌گذاری مشکوک
     */
    public function toggleSuspicious(int $id): array
    {
        $report = $this->bugReportModel->find($id);
        if (!$report) {
            return ['success' => false, 'message' => 'گزارش یافت نشد'];
        }

        $newStatus = $report->is_suspicious ? 0 : 1;
        $this->bugReportModel->update($id, ['is_suspicious' => $newStatus]);

        $text = $newStatus ? 'مشکوک' : 'عادی';
        $this->logger->warning('bug_report_suspicious_toggle', ['message' => "گزارش #{$id} به عنوان {$text} علامت‌گذاری شد"]);

        return [
            'success' => true,
            'is_suspicious' => $newStatus,
            'message' => "گزارش به عنوان «{$text}» علامت‌گذاری شد"
        ];
    }

    /**
     * حذف نرم
     */
    public function deleteReport(int $id): array
    {
        $report = $this->bugReportModel->find($id);
        if (!$report) {
            return ['success' => false, 'message' => 'گزارش یافت نشد'];
        }

        $this->bugReportModel->softDelete($id);
        $this->logger->warning('bug_report_deleted', ['message' => "گزارش #{$id} حذف شد"]);

        return ['success' => true, 'message' => 'گزارش با موفقیت حذف شد'];
    }

    /**
     * اعتبارسنجی
     */
    private function validateReport(array $data): array
    {
        $errors = [];

        if (empty(\trim($data['description'] ?? ''))) {
            $errors['description'] = 'توضیحات گزارش الزامی است';
        } elseif (\mb_strlen($data['description']) < 10) {
            $errors['description'] = 'توضیحات باید حداقل ۱۰ کاراکتر باشد';
        } elseif (\mb_strlen($data['description']) > 2000) {
            $errors['description'] = 'توضیحات حداکثر ۲۰۰۰ کاراکتر';
        }

        $validCategories = ['ui_issue', 'functional', 'payment', 'security', 'performance', 'content', 'other'];
        if (!empty($data['category']) && !\in_array($data['category'], $validCategories, true)) {
            $errors['category'] = 'دسته‌بندی نامعتبر';
        }

        return $errors;
    }

    /**
     * تشخیص اولویت خودکار
     */
    private function detectPriority(array $data): string
    {
        $category = $data['category'] ?? 'other';

        if ($category === 'security') {
            return 'critical';
        }

        if ($category === 'payment') {
            return 'high';
        }

        $desc = \mb_strtolower($data['description'] ?? '');
        $criticalKeywords = ['هک', 'نفوذ', 'دزدی', 'سرقت', 'پول', 'پرداخت نشد', 'موجودی کم شد', 'حساب خالی'];
        $highKeywords = ['خطا', 'ارور', 'کار نمیکنه', 'بسته میشه', 'لود نمیشه', 'سفید', 'خراب'];

        foreach ($criticalKeywords as $kw) {
            if (\mb_strpos($desc, $kw) !== false) {
                return 'critical';
            }
        }

        foreach ($highKeywords as $kw) {
            if (\mb_strpos($desc, $kw) !== false) {
                return 'high';
            }
        }

        return 'normal';
    }

    /**
     * آپلود اسکرین‌شات
     */
    private function uploadScreenshot(array $file): array
    {
        $result = $this->uploadService->upload(
            $file,
            'bug_reports',
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            3 * 1024 * 1024 // 3MB
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['message']];
        }

        return ['success' => true, 'path' => $result['path']];
    }

    /**
     * تشخیص مرورگر
     */
    private function parseBrowser(?string $ua): array
    {
        if (!$ua) {
            return ['browser' => null, 'os' => null];
        }

        $browser = 'نامشخص';
        $os = 'نامشخص';

        // Browser
        if (\preg_match('/Edg[e]?\/(\S+)/i', $ua)) {
            $browser = 'Edge';
        } elseif (\preg_match('/OPR\/(\S+)/i', $ua)) {
            $browser = 'Opera';
        } elseif (\preg_match('/Chrome\/(\S+)/i', $ua)) {
            $browser = 'Chrome';
        } elseif (\preg_match('/Firefox\/(\S+)/i', $ua)) {
            $browser = 'Firefox';
        } elseif (\preg_match('/Safari\/(\S+)/i', $ua) && !\preg_match('/Chrome/i', $ua)) {
            $browser = 'Safari';
        }

        // OS
        if (\preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (\preg_match('/Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (\preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        } elseif (\preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (\preg_match('/iPhone|iPad/i', $ua)) {
            $os = 'iOS';
        }

        return ['browser' => $browser, 'os' => $os];
    }

    /**
     * نوتیفیکیشن به ادمین‌ها
     */
    private function notifyAdmins(int $reportId, string $priority, string $category): void
    {
        $admins = $this->db->fetchAll("SELECT id FROM users WHERE role IN ('admin','superadmin') AND status = 1");

        $categoryLabels = [
            'security' => 'امنیتی', 'payment' => 'پرداخت',
            'functional' => 'عملکردی', 'ui_issue' => 'ظاهری',
        ];

        $catText = $categoryLabels[$category] ?? $category;
        $priText = $priority === 'critical' ? '🔴 بحرانی' : '🟠 بالا';

        foreach ($admins as $admin) {
            $adminId = (int)(\is_array($admin) ? $admin['id'] : ($admin->id ?? $admin));
            $this->notificationModel->create([
                'user_id' => $adminId,
                'type' => 'bug_report_critical',
                'title' => "گزارش باگ {$priText}",
                'message' => "گزارش باگ جدید ({$catText}) با اولویت {$priText} ثبت شد - شناسه: #{$reportId}",
                'link' => "/admin/bug-reports/{$reportId}",
            ]);
        }
    }

    // ─── Query Methods (برای Controllers) ───────────────────────

    public function getByUser(int $userId, int $perPage = 10, int $offset = 0): array
    {
        return $this->bugReportModel->getByUser($userId, $perPage, $offset);
    }

    public function find(int $id): ?object
    {
        return $this->bugReportModel->find($id);
    }

    public function getComments(int $reportId, bool $includeInternal = false): array
    {
        return $this->bugReportCommentModel->getByReport($reportId, $includeInternal);
    }
}
