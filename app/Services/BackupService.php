<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BackupLog;
use App\Contracts\LoggerInterface;

/**
 * BackupService — سرویس پشتیبان‌گیری و بازیابی دیتابیس
 *
 * ویژگی‌ها:
 * - ایجاد پشتیبان دستی یا خودکار
 * - بازیابی از فایل پشتیبان
 * - مدیریت پشتیبان‌های قدیمی
 * - فشرده‌سازی فایل‌های پشتیبان
 */
class BackupService extends \App\Services\BaseService
{
    private BackupLog $backupLogModel;
    private string $backupDir;

    public function __construct(BackupLog $backupLogModel, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->backupLogModel = $backupLogModel;
        $this->backupDir = realpath(__DIR__ . '/../../storage') ?: (__DIR__ . '/../../storage');
        $this->backupDir .= '/backups';

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * ایجاد پشتیبان دیتابیس
     */
    public function createBackup(?string $description = null): array
    {
        $cnfFile = null;
        try {
            $timestamp = date('YmdHis');
            $filename = "backup_{$timestamp}.sql";
            $filepath = $this->backupDir . '/' . $filename;

            // دریافت نام دیتابیس از تنظیمات
            $dbName = env('DB_DATABASE', 'chortke');
            $dbUser = env('DB_USERNAME', 'root');
            $dbPass = env('DB_PASSWORD', '');
            $dbHost = env('DB_HOST', 'localhost');

            // ساخت فایل موقت تنظیمات جهت مخفی‌سازی پسورد دیتابیس
            $cnfFile = tempnam(sys_get_temp_dir(), 'mycnf_');
            $cnfContent = sprintf("[client]\npassword=%s\n", $dbPass);
            file_put_contents($cnfFile, $cnfContent);
            chmod($cnfFile, 0600);

            // دستور mysqldump با استفاده از --defaults-extra-file
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --host=%s --user=%s %s > %s 2>&1',
                escapeshellarg($cnfFile),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception('mysqldump failed: ' . implode("\n", $output));
            }

            // فشرده‌سازی فایل
            $gzFilepath = $filepath . '.gz';
            exec("gzip " . escapeshellarg($filepath), $compressOutput, $compressCode);

            $fileSize = filesize($gzFilepath ?? $filepath);

            $this->logger->info('backup.created', [
                'filename' => $filename,
                'size' => $fileSize,
                'description' => $description,
                'timestamp' => $timestamp
            ]);

            // ذخیره اطلاعات پشتیبان
            $this->backupLogModel->logBackup([
                'filename' => $filename,
                'size' => $fileSize,
                'description' => $description,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'success' => true,
                'filename' => basename($gzFilepath ?? $filepath),
                'size' => $this->formatBytes($fileSize),
                'path' => $gzFilepath ?? $filepath,
                'timestamp' => $timestamp
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.creation_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } finally {
            if ($cnfFile && file_exists($cnfFile)) {
                unlink($cnfFile);
            }
        }
    }

    /**
     * دریافت لیست پشتیبان‌ها
     */
    public function getBackups(int $limit = 50, int $offset = 0): array
    {
        try {
            $logs = $this->backupLogModel->getRecentBackups($limit, $offset);

            return [
                'success' => true,
                'backups' => $logs,
                'count' => count($logs)
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getBackupById(int $backupId): ?array
    {
        return $this->backupLogModel->findById($backupId);
    }

    /**
     * حذف پشتیبان قدیمی‌ها (قدیمی‌تر از X روز)
     */
    public function cleanupOldBackups(int $daysToKeep = 30): array
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));

            // دریافت فایل‌های قدیمی
            $oldBackups = $this->backupLogModel->getOlderThan($cutoffDate);

            $deleted = 0;
            foreach ($oldBackups as $backup) {
                $backup = (array)$backup;
                $filepath = $this->backupDir . '/' . $backup['filename'];
                $gzPath = $filepath . '.gz';

                if (file_exists($gzPath)) {
                    unlink($gzPath);
                    $deleted++;
                } elseif (file_exists($filepath)) {
                    unlink($filepath);
                    $deleted++;
                }
            }

            // حذف سوابق
            $this->backupLogModel->deleteOlderThan($cutoffDate);

            $this->logger->info('backup.cleanup_completed', ['deleted' => $deleted]);

            return [
                'success' => true,
                'deleted' => $deleted,
                'message' => "Deleted {$deleted} old backups (older than {$daysToKeep} days)"
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.cleanup_failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * بازیابی از پشتیبان
     */
    public function restoreBackup(string $filename): array
    {
        $cnfFile = null;
        try {
            $filename = basename($filename);
            $filepath = $this->backupDir . '/' . $filename;
            $gzFilepath = $filepath . '.gz';

            $baseReal = realpath($this->backupDir);
            if ($baseReal === false) {
                throw new \Exception('Backup directory is invalid');
            }

            // بررسی وجود فایل
            if (file_exists($gzFilepath)) {
                $gzReal = realpath($gzFilepath);
                if ($gzReal === false || strpos($gzReal, $baseReal) !== 0) {
                    throw new \Exception('Path traversal detected or file is invalid');
                }
                // unzip
                exec("gunzip " . escapeshellarg($gzFilepath), $output, $exitCode);
                if ($exitCode !== 0) {
                    throw new \Exception('Failed to unzip backup file');
                }
            } elseif (file_exists($filepath)) {
                $fileReal = realpath($filepath);
                if ($fileReal === false || strpos($fileReal, $baseReal) !== 0) {
                    throw new \Exception('Path traversal detected or file is invalid');
                }
            } else {
                throw new \Exception('Backup file not found');
            }

            // دریافت تنظیمات دیتابیس
            $dbName = env('DB_DATABASE', 'chortke');
            $dbUser = env('DB_USERNAME', 'root');
            $dbPass = env('DB_PASSWORD', '');
            $dbHost = env('DB_HOST', 'localhost');

            // ساخت فایل موقت تنظیمات جهت مخفی‌سازی پسورد دیتابیس
            $cnfFile = tempnam(sys_get_temp_dir(), 'mycnf_');
            $cnfContent = sprintf("[client]\npassword=%s\n", $dbPass);
            file_put_contents($cnfFile, $cnfContent);
            chmod($cnfFile, 0600);

            // دستور mysql import با استفاده از --defaults-extra-file
            $command = sprintf(
                'mysql --defaults-extra-file=%s --host=%s --user=%s %s < %s 2>&1',
                escapeshellarg($cnfFile),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                throw new \Exception('mysql import failed: ' . implode("\n", $output));
            }

            $this->logger->info('backup.restored', [
                'filename' => $filename,
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            return [
                'success' => true,
                'message' => 'Backup restored successfully'
            ];

        } catch (\Exception $e) {
            $this->logger->error('backup.restore_failed', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } finally {
            if ($cnfFile && file_exists($cnfFile)) {
                unlink($cnfFile);
            }
        }
    }

    /**
     * دریافت آمار پشتیبان‌ها
     */
    public function getBackupStats(): array
    {
        try {
            $stats = $this->backupLogModel->getStats();

            return [
                'success' => true,
                'total_backups' => (int)($stats->total_backups ?? 0),
                'total_size' => $this->formatBytes((int)($stats->total_size ?? 0)),
                'last_backup' => $stats->last_backup ?? null,
                'first_backup' => $stats->first_backup ?? null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * تبدیل بایت به فرمت خوانا
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
