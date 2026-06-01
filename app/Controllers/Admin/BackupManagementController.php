<?php

namespace App\Controllers\Admin;

use App\Services\DatabaseService;
use Core\Logger;

/**
 * Controller: BackupManagementController
 * مدیریت پشتیبان‌گیری و بازیابی دیتابیس
 */
class BackupManagementController extends BaseAdminController
{
    private DatabaseService $databaseService;
    private Logger $logger;

    public function __construct(DatabaseService $databaseService, Logger $logger)
    {
        parent::__construct(null, null, null, null, $logger);
        $this->databaseService = $databaseService;
        $this->logger = $logger;
    }

    /**
     * نمایش لیست پشتیبان‌ها
     */
    public function index()
    {
        try {
            $backups = $this->databaseService->getBackups(50, 0); 
            $stats = $this->databaseService->getBackupStats();

            view('admin/backups/index', [
                'backups' => $backups['backups'] ?? [],
                'stats' => $stats,
                'success' => $backups['success'] ?? false
            ]);

        } catch (\Exception $e) {
            $this->logger->error('admin.backups.index.failed', ['error' => $e->getMessage()]);
            flash('خطا: دریافت لیست پشتیبان‌ها ناموفق بود', 'error');
            redirect('/admin/dashboard');
        }
    }

    /**
     * ایجاد پشتیبان جدید
     */
    public function createBackup()
    {
        try {
            $description = $_POST['description'] ?? null;

            $result = $this->databaseService->createBackup($description);

            if ($result['success']) {
                $this->logger->info('admin.backup.created', [
                    'filename' => $result['filename'],
                    'size' => $result['size']
                ]);
                flash("پشتیبان با موفقیت ایجاد شد: {$result['filename']}", 'success');
            } else {
                flash("خطا: {$result['error']}", 'error');
            }

            redirect('/admin/backups');

        } catch (\Exception $e) {
            $this->logger->error('admin.backup.create.failed', ['error' => $e->getMessage()]);
            flash('خطا: ایجاد پشتیبان ناموفق بود', 'error');
            redirect('/admin/backups');
        }
    }

    /**
     * بازیابی از پشتیبان (محدود به ادمین‌های ارشد فقط)
     */
    public function restoreBackup()
    {
        $this->requirePermission('super_admin');
        try {
            $backupId = $_POST['backup_id'] ?? null;

            if (!$backupId) {
                flash('شناسه پشتیبان الزامی است', 'error');
                redirect('/admin/backups');
                return;
            }

            $backup = $this->databaseService->getBackupById((int)$backupId);
            if (!$backup || empty($backup['filename'])) {
                flash('پشتیبان یافت نشد', 'error');
                redirect('/admin/backups');
                return;
            }

            $result = $this->databaseService->restoreBackup($backup['filename']);

            if ($result['success']) {
                $this->logger->info('admin.backup.restore.success', ['backup_id' => $backupId]);
                flash('بازیابی پشتیبان با موفقیت انجام شد', 'success');
            } else {
                $this->logger->error('admin.backup.restore.failed', [
                    'backup_id' => $backupId,
                    'error' => $result['error']
                ]);
                flash('خطا در بازیابی: ' . $result['error'], 'error');
            }

            redirect('/admin/backups');

        } catch (\Exception $e) {
            $this->logger->error('admin.backup.restore.failed', ['error' => $e->getMessage()]);
            flash('خطا: بازیابی ناموفق بود', 'error');
            redirect('/admin/backups');
        }
    }

    /**
     * بررسی صحت (Verify) فایل پشتیبان
     */
    public function verifyBackup()
    {
        try {
            $backupId = $_POST['backup_id'] ?? null;

            if (!$backupId) {
                flash('شناسه پشتیبان الزامی است', 'error');
                redirect('/admin/backups');
                return;
            }

            $backup = $this->databaseService->getBackupById((int)$backupId);
            if (!$backup || empty($backup['filename'])) {
                flash('پشتیبان یافت نشد', 'error');
                redirect('/admin/backups');
                return;
            }

            $result = $this->databaseService->verifyBackupIntegrity($backup['filename']);

            if ($result['success']) {
                $this->logger->info('admin.backup.verify.success', ['backup_id' => $backupId]);
                flash($result['message'], 'success');
            } else {
                $this->logger->warning('admin.backup.verify.failed', [
                    'backup_id' => $backupId,
                    'error' => $result['error']
                ]);
                flash('خطا: ' . $result['error'], 'error');
            }

            redirect('/admin/backups');

        } catch (\Exception $e) {
            $this->logger->error('admin.backup.verify.failed', ['error' => $e->getMessage()]);
            flash('خطا در بررسی صحت پشتیبان', 'error');
            redirect('/admin/backups');
        }
    }

    /**
     * نمایش آمار پشتیبان‌ها
     */
    public function stats()
    {
        try {
            $stats = $this->databaseService->getBackupStats();

            view('admin/backups/stats', ['stats' => $stats]);

        } catch (\Exception $e) {
            $this->logger->error('admin.backups.stats.failed', ['error' => $e->getMessage()]);
            flash('خطا: دریافت آمار ناموفق بود', 'error');
            redirect('/admin/dashboard');
        }
    }

    /**
     * پاک‌سازی پشتیبان‌های قدیمی
     */
    public function cleanup()
    {
        try {
            $daysToKeep = (int)($_POST['days_to_keep'] ?? 30);

            $result = $this->databaseService->cleanupOldBackups($daysToKeep);

            if ($result['success']) {
                $this->logger->info('admin.backup.cleanup', [
                    'deleted' => $result['deleted'],
                    'days_to_keep' => $daysToKeep
                ]);
                flash("پاک‌سازی انجام شد: {$result['deleted']} پشتیبان حذف شد", 'success');
            } else {
                flash("خطا: {$result['error']}", 'error');
            }

            redirect('/admin/backups');

        } catch (\Exception $e) {
            $this->logger->error('admin.backup.cleanup.failed', ['error' => $e->getMessage()]);
            flash('خطا: پاک‌سازی ناموفق بود', 'error');
            redirect('/admin/backups');
        }
    }
}
