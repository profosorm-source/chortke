<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;
/**
 * PerformanceOptimizationService - Database query optimization & batch operations
 * 
 * Features:
 * - Query optimization recommendations
 * - Batch insert/update operations
 * - Index suggestion
 * - Query execution time tracking
 * - N+1 query detection
 */
class PerformanceOptimizationServiceextends \App\Services\BaseService
{
    private Database $db;
    private array $queryTimes = [];
    private int $queryCount = 0;

    public function __construct(Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->db = $db;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Batch Operations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Batch insert records
     * 
     * More efficient than individual inserts for large datasets
     */
    public function batchInsert(string $table, array $records): array
    {
        if (empty($records)) {
            return ['ok' => false, 'error' => 'هیچ رکورد برای درج وجود ندارد'];
        }

        try {
            $startTime = microtime(true);
            $this->db->beginTransaction();

            $count = 0;
            foreach ($records as $record) {
                $columns = array_keys($record);
                $values = array_values($record);
                $placeholders = array_fill(0, count($values), '?');

                $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") 
                        VALUES (" . implode(', ', $placeholders) . ")";

                $this->db->query($sql, $values);
                $count++;
            }

            $this->db->commit();

            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            $this->logger->info('performance.batch_insert', [
                'table' => $table,
                'count' => $count,
                'execution_time_ms' => $executionTime
            ]);

            return [
                'ok' => true,
                'inserted' => $count,
                'execution_time_ms' => $executionTime
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('performance.batch_insert.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Batch update records
     */
    public function batchUpdate(string $table, array $updates, string $whereColumn, array $whereValues): array
    {
        if (empty($updates) || empty($whereValues)) {
            return ['ok' => false, 'error' => 'Invalid parameters'];
        }

        try {
            $startTime = microtime(true);
            $this->db->beginTransaction();

            $count = 0;
            foreach ($updates as $record) {
                $whereValue = array_shift($whereValues);
                
                $set = [];
                $values = [];
                foreach ($record as $column => $value) {
                    $set[] = "{$column} = ?";
                    $values[] = $value;
                }
                $values[] = $whereValue;

                $sql = "UPDATE {$table} SET " . implode(', ', $set) . 
                       " WHERE {$whereColumn} = ?";

                $this->db->query($sql, $values);
                $count++;
            }

            $this->db->commit();

            $executionTime = (microtime(true) - $startTime) * 1000;

            $this->logger->info('performance.batch_update', [
                'table' => $table,
                'count' => $count,
                'execution_time_ms' => $executionTime
            ]);

            return [
                'ok' => true,
                'updated' => $count,
                'execution_time_ms' => $executionTime
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('performance.batch_update.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Query Optimization
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Track query execution time
     */
    public function trackQueryTime(string $query, float $executionTime): void
    {
        $this->queryTimes[] = [
            'query' => $query,
            'time_ms' => $executionTime,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $this->queryCount++;

        // ⚠️ Flag slow queries
        if ($executionTime > 1000) { // Over 1 second
            $this->logger->warning('performance.slow_query', [
                'query' => substr($query, 0, 100),
                'time_ms' => $executionTime
            ]);
        }
    }

    /**
     * Get query performance stats
     */
    public function getQueryStats(): array
    {
        if (empty($this->queryTimes)) {
            return [
                'total_queries' => 0,
                'average_time_ms' => 0,
                'slowest_query' => null
            ];
        }

        $times = array_column($this->queryTimes, 'time_ms');
        $avg = array_sum($times) / count($times);
        $slowest = max($times);

        $slowestQuery = $this->queryTimes[array_search($slowest, $times)];

        return [
            'total_queries' => $this->queryCount,
            'total_time_ms' => array_sum($times),
            'average_time_ms' => $avg,
            'fastest_query_ms' => min($times),
            'slowest_query_ms' => $slowest,
            'slowest_query' => $slowestQuery['query'],
            'query_log' => array_slice($this->queryTimes, -10) // Last 10 queries
        ];
    }

    /**
     * Clear query log
     */
    public function clearQueryLog(): void
    {
        $this->queryTimes = [];
        $this->queryCount = 0;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Index Recommendations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get index recommendations for table
     */
    public function getIndexRecommendations(string $table): array
    {
        try {
            $result = $this->db->query(
                "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE 
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE()",
                [$table]
            )->fetchAll() ?? [];

            $recommendations = [];

            foreach ($result as $col) {
                $columnName = $col->COLUMN_NAME;
                $columnType = $col->COLUMN_TYPE;

                // ✅ Recommend index for foreign keys
                if (strpos($columnName, '_id') !== false) {
                    $recommendations[] = [
                        'column' => $columnName,
                        'reason' => 'Foreign key - frequent JOIN operations',
                        'suggestion' => "ALTER TABLE {$table} ADD INDEX idx_{$columnName} ({$columnName});"
                    ];
                }

                // ✅ Recommend index for status columns
                if (in_array($columnName, ['status', 'state', 'active'], true)) {
                    $recommendations[] = [
                        'column' => $columnName,
                        'reason' => 'Status column - frequent filtering',
                        'suggestion' => "ALTER TABLE {$table} ADD INDEX idx_{$columnName} ({$columnName});"
                    ];
                }

                // ✅ Recommend index for timestamp columns
                if (in_array($columnName, ['created_at', 'updated_at'], true)) {
                    $recommendations[] = [
                        'column' => $columnName,
                        'reason' => 'Timestamp - frequent sorting/filtering',
                        'suggestion' => "ALTER TABLE {$table} ADD INDEX idx_{$columnName} ({$columnName});"
                    ];
                }
            }

            return $recommendations;
        } catch (\Exception $e) {
            $this->logger->error('performance.index_recommendations.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Connection Pool Management
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get database connection stats
     */
    public function getConnectionStats(): array
    {
        try {
            $status = $this->db->query("SHOW STATUS LIKE 'Threads%'")->fetchAll() ?? [];
            
            $stats = [];
            foreach ($status as $row) {
                $stats[$row->Variable_name] = $row->Value;
            }

            return $stats;
        } catch (\Exception $e) {
            $this->logger->error('performance.connection_stats.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Data Aggregation & Caching Strategy
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Aggregate related data in single query (prevent N+1)
     * 
     * Example: Get influencers with their verification status and profile stats
     */
    public function aggregateInfluencerData(int $limit = 20, int $offset = 0): array
    {
        try {
            $sql = "
                SELECT 
                    ip.id,
                    ip.user_id,
                    ip.display_name,
                    ip.platform,
                    ip.followers,
                    ip.avg_engagement,
                    ip.status,
                    COUNT(DISTINCT t.id) as task_count,
                    COUNT(DISTINCT w.id) as withdrawal_count,
                    COALESCE(SUM(w.amount), 0) as total_withdrawn
                FROM influencer_profiles ip
                LEFT JOIN tasks t ON t.influencer_id = ip.id
                LEFT JOIN wallet_transactions w ON w.influencer_id = ip.id AND w.type = 'withdrawal'
                GROUP BY ip.id
                ORDER BY ip.followers DESC
                LIMIT ? OFFSET ?
            ";

            return $this->db->query($sql, [$limit, $offset])->fetchAll() ?? [];
        } catch (\Exception $e) {
            $this->logger->error('performance.aggregate_influencer_data.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Aggregate social task data
     */
    public function aggregateSocialTaskData(int $limit = 20, int $offset = 0): array
    {
        try {
            $sql = "
                SELECT 
                    sa.id,
                    sa.title,
                    sa.platform,
                    sa.reward,
                    sa.status,
                    COUNT(DISTINCT se.id) as execution_count,
                    SUM(CASE WHEN se.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    AVG(CASE WHEN se.proof IS NOT NULL THEN 1 ELSE 0 END) as proof_rate
                FROM social_ads sa
                LEFT JOIN social_executions se ON se.ad_id = sa.id
                WHERE sa.deleted_at IS NULL
                GROUP BY sa.id
                ORDER BY sa.created_at DESC
                LIMIT ? OFFSET ?
            ";

            return $this->db->query($sql, [$limit, $offset])->fetchAll() ?? [];
        } catch (\Exception $e) {
            $this->logger->error('performance.aggregate_social_task_data.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Caching Strategy Recommendations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get caching recommendations
     */
    public function getCachingRecommendations(): array
    {
        return [
            [
                'entity' => 'Search Results',
                'ttl' => 300,
                'reason' => 'Frequently searched, query results change slowly',
                'cache_key' => 'search:{module}:{hash}'
            ],
            [
                'entity' => 'User Profiles',
                'ttl' => 900,
                'reason' => 'Accessed frequently, updated infrequently',
                'cache_key' => 'profile:user:{user_id}'
            ],
            [
                'entity' => 'Settings',
                'ttl' => 3600,
                'reason' => 'Admin settings rarely change',
                'cache_key' => 'setting:{setting_key}'
            ],
            [
                'entity' => 'Statistics',
                'ttl' => 600,
                'reason' => 'Expensive aggregation queries',
                'cache_key' => 'stat:{type}:{period}'
            ],
            [
                'entity' => 'Categories/Taxonomies',
                'ttl' => 86400,
                'reason' => 'Static data, rarely changes',
                'cache_key' => 'taxonomy:{type}'
            ]
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Database Maintenance
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Optimize tables (ANALYZE, OPTIMIZE)
     */
    public function optimizeDatabase(): array
    {
        try {
            $tables = $this->db->query(
                "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE()"
            )->fetchAll() ?? [];

            $results = [];
            foreach ($tables as $table) {
                $tableName = $table->TABLE_NAME;
                // Escape table name with backticks
                $this->db->query("ANALYZE TABLE `{$tableName}`");
                $this->db->query("OPTIMIZE TABLE `{$tableName}`");
                $results[] = $tableName;
            }

            $this->logger->info('performance.database_optimized', ['tables' => count($results)]);

            return [
                'ok' => true,
                'optimized_tables' => $results,
                'count' => count($results)
            ];
        } catch (\Exception $e) {
            $this->logger->error('performance.optimization.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get table statistics
     */
    public function getTableStats(): array
    {
        try {
            $stats = $this->db->query(
                "SELECT 
                    TABLE_NAME,
                    TABLE_ROWS as row_count,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) as size_mb
                FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                ORDER BY data_length DESC"
            )->fetchAll() ?? [];

            return array_map(function ($stat) {
                return [
                    'table' => $stat->TABLE_NAME,
                    'rows' => $stat->row_count,
                    'size_mb' => $stat->size_mb
                ];
            }, $stats);
        } catch (\Exception $e) {
            $this->logger->error('performance.table_stats.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}

