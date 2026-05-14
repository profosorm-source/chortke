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

            // دریافت اطلاعات کامل دیتابیس از لایه پیکربندی مرکزی
            $dbConfig = config('database');
            $dbName = $dbConfig['name'] ?? 'chortke';
            $dbUser = $dbConfig['user'] ?? 'root';
            $dbPass = $dbConfig['pass'] ?? '';
            $dbHost = $dbConfig['host'] ?? 'localhost';

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
        $tempSqlFile = null;
        try {
            $filename = basename($filename);
            // Validate filename format strictly
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.(sql|gz)$/i', $filename)) {
                throw new \Exception('نام فایل پشتیبان نامعتبر است');
            }

            $baseReal = realpath($this->backupDir);
            if ($baseReal === false) {
                throw new \Exception('Backup directory is invalid');
            }

            $filepath = $this->backupDir . '/' . $filename;
            if (!file_exists($filepath)) {
                throw new \Exception('Backup file not found');
            }

            $fileReal = realpath($filepath);
            if ($fileReal === false || strpos($fileReal, $baseReal) !== 0) {
                throw new \Exception('Path traversal detected or file is invalid');
            }

            $restoreSourcePath = $filepath;
            $isCompressed = (strtolower(substr($filename, -3)) === '.gz');

            // Safe non-destructive decompression using native PHP zlib streams to local temp
            if ($isCompressed) {
                if (!function_exists('gzopen')) {
                    throw new \Exception('Zlib extraction is not supported in this PHP environment');
                }

                $tempSqlFile = tempnam(sys_get_temp_dir(), 'dbrestore_');
                if ($tempSqlFile === false) {
                    throw new \Exception('Failed to create temporary extraction file');
                }

                $gz = gzopen($fileReal, 'rb');
                $out = fopen($tempSqlFile, 'wb');
                
                if (!$gz || !$out) {
                    if ($gz) gzclose($gz);
                    if ($out) fclose($out);
                    throw new \Exception('Failed to open backup files for decompression');
                }

                while (!gzeof($gz)) {
                    $data = gzread($gz, 65536);
                    if ($data !== false) {
                        fwrite($out, $data);
                    }
                }
                gzclose($gz);
                fclose($out);

                $restoreSourcePath = $tempSqlFile;
            }

            // دریافت تنظیمات دیتابیس از لایه مرکزی
            $dbConfig = config('database');
            $dbName = $dbConfig['name'] ?? 'chortke';
            $dbUser = $dbConfig['user'] ?? 'root';
            $dbPass = $dbConfig['pass'] ?? '';
            $dbHost = $dbConfig['host'] ?? 'localhost';

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
                escapeshellarg($restoreSourcePath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                // Log the output privately, do NOT leak it back to user
                $this->logger->error('backup.restore_command_failed', [
                    'filename' => $filename,
                    'exit_code' => $exitCode,
                    'output' => implode("\n", $output)
                ]);
                throw new \Exception('خطای سیستمی در بازیابی پایگاه داده. لطفاً لاگ‌ها را بررسی کنید.');
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
            if ($tempSqlFile && file_exists($tempSqlFile)) {
                unlink($tempSqlFile);
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
