<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Models\BackupLog;
use App\Contracts\LoggerInterface;
use Exception;

/**
 * DatabaseService
 * مدیر مرکزی دیتابیس برای چرخه حیات داده‌ها (Data Retention و Archival) و عملیات Backup/Restore
 */
class DatabaseService
{

    private BackupLog $backupLogModel;
    private string $backupDir;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger,
        BackupLog $backupLogModel
    )
    {        $this->db = $db;
        $this->logger = $logger;

        
        $this->backupLogModel = $backupLogModel;
        
        $this->backupDir = realpath(__DIR__ . '/../../storage') ?: (__DIR__ . '/../../storage');
        $this->backupDir .= '/backups';

        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }
    }

    // ====================================================================================
    // 1. Data Maintenance (Retention & Archival)
    // ====================================================================================

    public function runDailyMaintenance(): array
    {
        $this->logger->info('database.maintenance.started');
        
        $results = [
            'retention' => $this->executeDataRetention(),
            'archival' => $this->executeArchival(),
            'backup_cleanup' => $this->cleanupOldBackups(30),
        ];

        $this->logger->info('database.maintenance.completed', $results);
        return $results;
    }

    private function executeDataRetention(): array
    {
        $deletedSessions = 0;
        $deletedLogs = 0;

        try {
            $stmt = $this->db->query("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $deletedSessions = $stmt->rowCount();

            $stmt = $this->db->query("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $deletedLogs = $stmt->rowCount();

            return [
                'status' => 'success',
                'cleared_sessions' => $deletedSessions,
                'cleared_logs' => $deletedLogs,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('database.retention.failed', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function executeArchival(): array
    {
        try {
            $this->db->query("CREATE TABLE IF NOT EXISTS transactions_archive LIKE transactions");
            $this->db->beginTransaction();

            $this->db->query("INSERT INTO transactions_archive SELECT * FROM transactions WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $stmt = $this->db->query("DELETE FROM transactions WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $deletedFromMain = $stmt->rowCount();

            $this->db->commit();

            return ['status' => 'success', 'archived_records' => $deletedFromMain];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            $this->logger->error('database.archival.failed', ['error' => $e->getMessage()]);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    // ====================================================================================
    // 2. Backup & Restore (Replacing BackupService)
    // ====================================================================================

    public function createBackup(?string $description = null): array
    {
        $timestamp = date('YmdHis');
        $filename = "backup_{$timestamp}.sql";
        $filepath = $this->backupDir . '/' . $filename;
        $cnfFile = null;

        try {
            $dbConfig = config('database');
            $cnfFile = tempnam(sys_get_temp_dir(), 'mycnf_');
            file_put_contents($cnfFile, sprintf("[client]\npassword=%s\n", $dbConfig['pass'] ?? ''));
            chmod($cnfFile, 0600);

            $mysqlDumpPath = config('database.mysqldump_path', 'mysqldump');
            
            $command = sprintf(
                '%s --defaults-extra-file=%s --host=%s --user=%s %s > %s 2>&1',
                $mysqlDumpPath,
                escapeshellarg($cnfFile),
                escapeshellarg($dbConfig['host'] ?? 'localhost'),
                escapeshellarg($dbConfig['user'] ?? 'root'),
                escapeshellarg($dbConfig['name'] ?? 'chortke'),
                escapeshellarg($filepath)
            );

            exec($command, $output, $exitCode);
            if ($exitCode !== 0) throw new Exception('mysqldump failed: ' . implode("\n", $output));

            $fileSize = filesize($filepath);
            $checksum = hash_file('sha256', $filepath);

            $this->backupLogModel->logBackup([
                'request_id' => $_SERVER['REQUEST_ID'] ?? null,
                'status' => 'completed',
                'type' => 'manual',
                'file_path' => basename($filepath),
                'size_bytes' => $fileSize,
                'checksum' => $checksum,
                'description' => $description,
                'created_at' => date('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);

            return ['success' => true, 'filename' => basename($filepath), 'size' => $this->formatBytes($fileSize), 'path' => $filepath];
        } catch (\Exception $e) {
            $this->logger->error('database.backup_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            if ($cnfFile && file_exists($cnfFile)) @unlink($cnfFile);
        }
    }

    public function verifyBackupIntegrity(string $filename): array
    {
        $filepath = $this->backupDir . '/' . basename($filename);
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'فایل بک‌آپ یافت نشد.'];
        }

        $backupRecord = $this->backupLogModel->findByFilename(basename($filename));
        if (!$backupRecord || empty($backupRecord['checksum'])) {
            return ['success' => false, 'error' => 'اطلاعات هَش (Checksum) فایل در دیتابیس موجود نیست.'];
        }

        $currentChecksum = hash_file('sha256', $filepath);
        if ($currentChecksum !== $backupRecord['checksum']) {
            $this->logger->critical('database.backup.tampered', ['file' => $filename]);
            return ['success' => false, 'error' => 'عدم تطابق هَش! فایل بک‌آپ دستکاری یا خراب شده است.'];
        }

        return ['success' => true, 'message' => 'صحت فایل بک‌آپ کاملاً تایید شد.'];
    }

    public function restoreBackup(string $filename, bool $skipSnapshot = false): array
    {
        $cnfFile = null;
        try {
            if (!$skipSnapshot) {
                $snapshotResult = $this->createBackup('pre-restore-snapshot-' . time());
                if (!$snapshotResult['success']) throw new Exception('Pre-restore snapshot failed.');
            }

            $filepath = $this->backupDir . '/' . basename($filename);
            if (!file_exists($filepath)) throw new Exception('Backup file not found.');

            $backupRecord = $this->backupLogModel->findByFilename(basename($filename));
            if ($backupRecord && !empty($backupRecord['checksum'])) {
                $currentChecksum = hash_file('sha256', $filepath);
                if ($currentChecksum !== $backupRecord['checksum']) {
                    throw new Exception('Checksum mismatch! Backup file may be corrupted.');
                }
            }

            $dbConfig = config('database');
            $cnfFile = tempnam(sys_get_temp_dir(), 'mycnf_');
            file_put_contents($cnfFile, sprintf("[client]\npassword=%s\n", $dbConfig['pass'] ?? ''));
            chmod($cnfFile, 0600);

            $mysqlPath = config('database.mysql_path', 'mysql');
            $command = sprintf(
                '%s --defaults-extra-file=%s --host=%s --user=%s %s < %s 2>&1',
                escapeshellcmd($mysqlPath),
                escapeshellarg($cnfFile),
                escapeshellarg($dbConfig['host'] ?? 'localhost'),
                escapeshellarg($dbConfig['user'] ?? 'root'),
                escapeshellarg($dbConfig['name'] ?? 'chortke'),
                escapeshellarg($filepath)
            );

            exec($command, $output, $exitCode);
            if ($exitCode !== 0) throw new Exception('MySQL import failed: ' . implode("\n", $output));

            $this->logger->info('database.restored', ['filename' => $filename]);
            return ['success' => true, 'message' => 'Restored successfully'];
        } catch (\Exception $e) {
            $this->logger->error('database.restore_failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        } finally {
            if ($cnfFile && file_exists($cnfFile)) @unlink($cnfFile);
        }
    }

    public function getBackups(int $limit = 50, int $offset = 0): array
    {
        try {
            $logs = $this->backupLogModel->getRecentBackups($limit, $offset);
            return ['success' => true, 'backups' => $logs, 'count' => count($logs)];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getBackupById(int $backupId): ?array
    {
        return $this->backupLogModel->findById($backupId);
    }

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
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function cleanupOldBackups(int $daysToKeep = 30): array
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));
            $oldBackups = $this->backupLogModel->getOlderThan($cutoffDate);
            $deleted = 0;

            foreach ($oldBackups as $backup) {
                $filepath = $this->backupDir . '/' . basename(((array)$backup)['file_path']);
                if (file_exists($filepath)) {
                    @unlink($filepath);
                    $deleted++;
                }
            }
            $this->backupLogModel->deleteOlderThan($cutoffDate);

            return ['success' => true, 'deleted' => $deleted];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

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
