<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Core\Scheduler;

/**
 * CronController — مدیریت Cron Jobs
 */
class CronController extends BaseAdminController
{
    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        parent::__construct();
        $this->scheduler = $scheduler;
    }

    public function index(): void
    {
        $this->requirePermission('admin.view_cron_jobs');
        $this->view('admin/cron/index', ['title' => 'مدیریت Cron Jobs']);
    }

    /**
     * اجرای دستی جاب‌ها
     * ⚠️ توجه: این متد باید فقط تحت شرایط خاص و با مجوز بالا اجرا شود.
     */
    public function run(): void
    {
        $this->requirePermission('admin.execute_cron_jobs');

        if (!$this->request->isPost()) {
            $this->jsonError('متد نامعتبر است', [], 405);
        }

        try {
            $this->logger->info('admin.cron_manual_trigger', [
                'admin_id' => $this->userId(),
                'ip' => $this->request->ip()
            ]);

            // تعریف فلگ اختصاصی برای اجازه اجرا در محیط وب و جلوگیری از exit()
            if (!defined('INTERNAL_APP_CRON_TRIGGER')) {
                define('INTERNAL_APP_CRON_TRIGGER', true);
            }

            // پشتیبانی از اجرای یک جاب خاص اگر ارسال شده باشد
            $jobName = $this->request->get('job');
            $_SERVER['argv'] = ['cron.php'];
            if ($jobName) {
                $_SERVER['argv'][] = '--job=' . (string)$jobName;
            }

            // اجرای فایل کرون اصلی سیستم (که خود شامل تمام تعریف جاب‌ها و منطق اجراست)
            $results = [];
            if (file_exists(BASE_PATH . '/cron.php')) {
                ob_start();
                // استفاده از require به جای require_once برای اطمینان از اجرا در هر ریکوئست
                require BASE_PATH . '/cron.php';
                $consoleOutput = ob_get_clean();
            }

            // هندل کردن وضعیت skipped در صورت فعال بودن لاک فایل سراسری
            if (empty($results) && isset($consoleOutput) && str_contains($consoleOutput, '[SKIP]')) {
                 $results = ['system' => ['status' => 'skipped', 'reason' => 'cron_file_lock_active']];
            }
            
            $this->jsonSuccess('Cron jobs executed successfully', [
                'results' => $results,
                'executed_at' => date('Y-m-d H:i:s')
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('admin.cron_execution_failed', [
                'error' => $e->getMessage(),
                'admin_id' => $this->userId()
            ]);
            $this->jsonError('خطا در اجرای جاب‌ها: ' . $e->getMessage(), [], 500);
        }
    }
}