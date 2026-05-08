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

            // جلوگیری از اجرای همزمان با لاک دیتابیسی (اختیاری اگر در Scheduler پیاده شده باشد)
            
            // شبیه‌سازی اجرای CLI برای فایل cron.php قدیمی (اگر هنوز لازم است)
            if (file_exists(BASE_PATH . '/cron.php')) {
                // با احتیاط زیاد: در آینده این باید کاملاً به Scheduler منتقل شود.
                ob_start();
                $_SERVER['argv'] = ['cron.php'];
                require_once BASE_PATH . '/cron.php';
                ob_end_clean();
            }

            $results = $this->scheduler->run();
            
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