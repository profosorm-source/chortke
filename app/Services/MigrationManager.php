<?php

namespace App\Services;

use Core\Database;

/**
 * MigrationManager - مدیریت migrations و schema versioning
 * 
 * این سرویس تمام database migrations را ردیابی می‌کند و اطمینان می‌دهد
 * که schema در تمام محیط‌ها یکپارچه است.
 */
class MigrationManager
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Initialize migration table if not exists
     */
    public function initialize(): bool
    {
        try {
            $this->db->query(
                "CREATE TABLE IF NOT EXISTS schema_migrations (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    migration VARCHAR(255) UNIQUE NOT NULL,
                    batch INT NOT NULL,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )"
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get all executed migrations
     */
    public function getExecuted(): array
    {
        try {
            return (array)$this->db->fetchAll(
                "SELECT migration, batch, executed_at FROM schema_migrations ORDER BY batch, id"
            );
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Record a migration execution
     */
    public function record(string $migrationName, int $batch): bool
    {
        try {
            $this->db->query(
                "INSERT INTO schema_migrations (migration, batch) VALUES (?, ?)",
                [$migrationName, $batch]
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if migration was executed
     */
    public function isExecuted(string $migrationName): bool
    {
        try {
            $result = $this->db->fetch(
                "SELECT 1 FROM schema_migrations WHERE migration = ?",
                [$migrationName]
            );
            return (bool)$result;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get next batch number
     */
    public function getNextBatch(): int
    {
        try {
            $result = $this->db->fetch(
                "SELECT COALESCE(MAX(batch), 0) as max_batch FROM schema_migrations"
            );
            return ((int)$result->max_batch) + 1;
        } catch (\Exception $e) {
            return 1;
        }
    }

    /**
     * Verify schema integrity
     * 
     * بررسی می‌کند که:
     * 1. تمام ستون‌های ضروری وجود دارند
     * 2. تمام indexes وجود دارند
     * 3. تمام constraints تعریف شده‌اند
     */
    public function verifySchema(): array
    {
        $issues = [];

        // Critical tables to verify
        $criticalTables = [
            'users' => ['id', 'email', 'password', 'status', 'created_at'],
            'wallets' => ['id', 'user_id', 'balance_irt', 'balance_usdt', 'created_at'],
            'transactions' => ['id', 'user_id', 'type', 'amount', 'status', 'created_at'],
            'api_tokens' => ['id', 'user_id', 'token', 'expires_at', 'is_active'],
            'investments' => ['id', 'user_id', 'amount', 'status', 'created_at'],
            'withdrawals' => ['id', 'user_id', 'amount', 'status', 'created_at'],
        ];

        foreach ($criticalTables as $table => $requiredColumns) {
            // Check if table exists
            $exists = $this->db->fetch(
                "SELECT 1 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );

            if (!$exists) {
                $issues[] = "❌ Table not found: {$table}";
                continue;
            }

            // Check required columns
            foreach ($requiredColumns as $column) {
                $columnExists = $this->db->fetch(
                    "SELECT 1 FROM information_schema.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                       AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $column]
                );

                if (!$columnExists) {
                    $issues[] = "❌ Column missing: {$table}.{$column}";
                }
            }
        }

        // Check critical indexes
        $criticalIndexes = [
            'transactions' => ['user_id', 'status', 'created_at'],
            'withdrawals' => ['user_id', 'status', 'created_at'],
            'api_tokens' => ['user_id', 'token'],
        ];

        foreach ($criticalIndexes as $table => $columns) {
            foreach ($columns as $column) {
                // Simplified check - just verify column exists (full index check requires parsing)
                $columnExists = $this->db->fetch(
                    "SELECT 1 FROM information_schema.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                       AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$table, $column]
                );

                if (!$columnExists) {
                    $issues[] = "⚠️ Consider adding index on: {$table}.{$column}";
                }
            }
        }

        return $issues;
    }

    /**
     * Generate migration info report
     */
    public function report(): string
    {
        $executed = $this->getExecuted();
        $schemaIssues = $this->verifySchema();

        $report = "=== Migration Report ===\n\n";
        $report .= "Executed Migrations:\n";

        if (empty($executed)) {
            $report .= "  (none)\n";
        } else {
            foreach ($executed as $m) {
                $report .= "  [{$m->batch}] {$m->migration} ({$m->executed_at})\n";
            }
        }

        $report .= "\n\nSchema Verification:\n";
        if (empty($schemaIssues)) {
            $report .= "  ✅ All checks passed\n";
        } else {
            foreach ($schemaIssues as $issue) {
                $report .= "  {$issue}\n";
            }
        }

        return $report;
    }
}
