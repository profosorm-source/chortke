<?php

namespace App\Controllers\Admin;

use Core\Logger;
use App\Services\ExportService;
use App\Controllers\Admin\BaseAdminController;

/**
 * SystemLogController - مدیریت لاگ‌های سیستم
 */
class SystemLogController extends BaseAdminController
{
    private Logger $logger;
    private ExportService $exportService;

    public function __construct(Logger $logger, ExportService $exportService)
    {
        parent::__construct();
        $this->logger = $logger;
        $this->exportService = $exportService;
    }

    /**
     * لیست لاگ‌های سیستم
     */
    public function index()
    {
        try {
            $page = max(1, (int)($this->request->get('page') ?? 1));
            $level = $this->request->get('level');
            $userId = $this->request->get('user_id') ? (int)$this->request->get('user_id') : null;

            $result = $this->logger->getSystemLogs(
                page: $page,
                perPage: 50,
                level: $level ?: null,
                userId: $userId
            );

            $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

            return view('admin.system-logs.index', [
                'user' => auth()->user(),
                'title' => 'لاگ‌های سیستم',
                'logs' => $result['logs'],
                'total' => $result['total'],
                'page' => $result['page'],
                'totalPages' => $result['totalPages'],
                'levels' => $levels,
                'filters' => [
                    'level' => $level,
                    'user_id' => $userId,
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('system_log.index.failed', [
                'error' => $e->getMessage()
            ]);
            return view('errors.500');
        }
    }

    /**
     * مشاهده فایل لاگ
     */
    public function viewFile()
    {
        try {
            $date = $this->request->get('date') ?: date('Y-m-d');
            
            // اعتبارسنجی فرمت تاریخ
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $_SESSION['error'] = 'فرمت تاریخ نامعتبر است';
                return redirect('/admin/system-logs');
            }

            $logFile = dirname(__DIR__, 3) . '/storage/logs/' . $date . '.log';
            
            if (!file_exists($logFile)) {
                $_SESSION['error'] = 'فایل لاگ یافت نشد';
                return redirect('/admin/system-logs');
            }

            $content = file_get_contents($logFile);
            $lines = explode("\n", $content);
            $lines = array_filter($lines); // حذف خطوط خالی

            return view('admin.system-logs.view-file', [
                'user' => auth()->user(),
                'title' => 'مشاهده فایل لاگ',
                'date' => $date,
                'lines' => $lines,
                'fileSize' => filesize($logFile),
            ]);

        } catch (\Exception $e) {
    $this->logger->error('system_log.view_file.failed', [
        'channel' => 'system_log',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'date' => $date ?? null,
    ]);
            
            $_SESSION['error'] = 'خطا در خواندن فایل';
            return redirect('/admin/system-logs');
        }
    }

    /**
     * دانلود فایل لاگ
     */
    public function downloadFile()
    {
        try {
            $date = $this->request->get('date') ?: date('Y-m-d');
            
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                die('Invalid date format');
            }

            $logFile = dirname(__DIR__, 3) . '/storage/logs/' . $date . '.log';
            
            if (!file_exists($logFile)) {
                die('File not found');
            }

            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="' . basename($logFile) . '"');
            header('Content-Length: ' . filesize($logFile));
            
            readfile($logFile);
            exit;

        } catch (\Exception $e) {
    $this->logger->error('system_log.download_file.failed', [
        'channel' => 'system_log',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'date' => $date ?? null,
    ]);
    die('Error downloading file');
}
    }

    /**
     * پاکسازی لاگ‌های قدیمی
     */
    public function cleanup()
    {
        try {
            $days = (int)($this->request->post('days') ?? 30);
            
            $deleted = $this->logger->cleanOldLogs($days);

            $this->logger->info('system_log.cleanup', [
                'days' => $days,
                'deleted' => $deleted,
                'admin_id' => user_id()
            ]);

            $_SESSION['success'] = "{$deleted} فایل/رکورد حذف شد";
            return redirect('/admin/system-logs');

        } catch (\Exception $e) {
            $this->logger->error('system_log.cleanup.failed', [
                'error' => $e->getMessage()
            ]);
            
            $_SESSION['error'] = 'خطا در پاکسازی';
            return redirect('/admin/system-logs');
        }
    }
}
