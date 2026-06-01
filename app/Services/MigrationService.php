<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Redis;
use App\Contracts\LoggerInterface;

/**
 * MigrationService
 * مدیر ارکستراسیون ورژن‌های دیتابیس (Schema Migrations)
 */
class MigrationService
{

    private string $migrationsDir;


    private \Core\Redis $redis;
    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Redis $redis,
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    )
    {        $this->redis = $redis;
        $this->db = $db;
        $this->logger = $logger;

        
        $this->migrationsDir = realpath(__DIR__ . '/../../database/migrations') ?: (__DIR__ . '/../../database/migrations');
    }

    public function runMigrations(): array
    {
        $this->initializeSchemaTable();
        
        // Distributed Lock for safe multi-node deploy
        $redis = $this->redis;
        if ($redis !== null && $redis->isAvailable()) {
            try {
                $lock = $redis->getClient()->set('schema_migration_lock', 'locked', ['nx', 'ex' => 300]);
                if (!$lock) {
                    return ['success' => false, 'message' => 'Migration is already running on another node.'];
                }
            } catch (\Throwable $e) {
                $redis = null;
            }
        }

        try {
            $executedList = array_column($this->getExecutedMigrations(), 'migration');
            $allFiles = glob($this->migrationsDir . '/*.sql');
            sort($allFiles);

            $pending = [];
            foreach ($allFiles as $filePath) {
                $filename = basename($filePath);
                if (!in_array($filename, $executedList)) {
                    $pending[] = $filePath;
                }
            }

            if (empty($pending)) {
                return ['success' => true, 'executed' => 0, 'message' => 'Database schema is already up to date.'];
            }

            $batch = $this->getNextMigrationBatch();
            $executedCount = 0;
            $errors = [];

            foreach ($pending as $file) {
                $filename = basename($file);
                $checksum = hash_file('sha256', $file);
                $sql = file_get_contents($file);
                
                $isDdl = (bool)preg_match('/\b(ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', $sql);
                $useTx = !$isDdl;

                if ($useTx) {
                    $this->db->beginTransaction();
                }
                
                try {
                    $this->db->getPdo()->exec($sql);
                    
                    $this->db->query(
                        "INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)",
                        [$filename, $batch]
                    );
                    
                    if ($useTx && $this->db->inTransaction()) {
                        $this->db->commit();
                    }
                    $executedCount++;
                    $this->logger->info('database.migration.executed', ['migration' => $filename]);
                } catch (\Throwable $e) {
                    if ($useTx && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    $errors[] = "Failed executing {$filename}: " . $e->getMessage();
                    $this->logger->critical('database.migration.failed', ['migration' => $filename, 'error' => $e->getMessage()]);
                    break;
                }
            }

            return [
                'success' => empty($errors),
                'executed' => $executedCount,
                'batch' => $batch,
                'errors' => $errors,
                'message' => "Executed {$executedCount} migrations."
            ];
        } finally {
            if ($redis !== null) {
                try {
                    $redis->getClient()->del('schema_migration_lock');
                } catch (\Throwable $e) {}
            }
        }
    }

    public function rollbackMigrations(int $steps = 1): array
    {
        return [
            'success' => false,
            'message' => 'Rollback is partially supported for SQL files. Full PHP-class support required.'
        ];
    }

    private function initializeSchemaTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) UNIQUE NOT NULL,
                batch INT NOT NULL,
                checksum VARCHAR(64) NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    private function getExecutedMigrations(): array
    {
        return (array)$this->db->fetchAll("SELECT migration, batch, executed_at FROM schema_migrations ORDER BY batch, id");
    }

    private function getNextMigrationBatch(): int
    {
        $result = $this->db->fetch("SELECT COALESCE(MAX(batch), 0) as max_batch FROM schema_migrations");
        return ((int)$result->max_batch) + 1;
    }
}
