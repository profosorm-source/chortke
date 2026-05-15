<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;
/**
 * PerformanceOptimizationService - Comprehensive database optimization & monitoring
 * 
 * Consolidated service combining performance monitoring, batch operations, and query analysis.
 * Merged from QueryOptimizationService to eliminate duplication.
 * 
 * Features:
 * - Batch insert/update operations (large datasets)
 * - Query execution time tracking & monitoring
 * - Slow query detection & logging
 * - EXPLAIN query analysis with optimization suggestions
 * - Index recommendations (single + composite)
 * - Database health check
 * - Query performance statistics
 * - Data aggregation (prevent N+1 queries)
 * - Caching strategy recommendations
 */
class PerformanceOptimizationService
extends \App\Services\BaseService
{
    private Database $db;
    private array $queryTimes = [];
    private int $queryCount = 0;
    private float $slowQueryThreshold = 1.0; // seconds
    private bool $logSlowQueries = true;

    public function __construct(Database $db, LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->db = $db;
        $this->slowQueryThreshold = (float)config('logging.performance.slow_query_threshold', 1.0);
        $this->logSlowQueries = (bool)config('logging.performance.log_slow_queries', true);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Batch Operations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Batch insert records - uses multi-row INSERT for efficiency
     * 
     * More efficient than individual inserts for large datasets
     * Optimized: Single multi-row INSERT instead of loop
     */
    public function batchInsert(string $table, array $records): array
    {
        if (empty($records)) {
            return ['ok' => false, 'error' => 'هیچ رکورد برای درج وجود ندارد'];
        }

        if (!$this->isValidIdentifier($table)) {
            return ['ok' => false, 'error' => 'نام جدول نامعتبر است'];
        }

        try {
            $startTime = microtime(true);
            
            // Extract column names from first record
            $columns = array_keys($records[0]);
            foreach ($columns as $column) {
                if (!$this->isValidIdentifier((string)$column)) {
                    return ['ok' => false, 'error' => 'نام ستون نامعتبر است'];
                }
            }

            $quotedTable = $this->quoteIdentifier($table);
            $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
            $columnList = implode(', ', $quotedColumns);
            
            // Build placeholders and collect all values
            $valueParts = [];
            $allValues = [];
            
            foreach ($records as $record) {
                $placeholders = array_fill(0, count($columns), '?');
                $valueParts[] = '(' . implode(', ', $placeholders) . ')';
                $allValues = array_merge($allValues, array_values($record));
            }
            
            // Single multi-row INSERT query (much faster than loop)
            $sql = "INSERT INTO {$quotedTable} ({$columnList}) VALUES " . implode(', ', $valueParts);
            
            $this->db->beginTransaction();
            $this->db->query($sql, $allValues);
            $this->db->commit();

            $executionTime = (microtime(true) - $startTime) * 1000; // ms

            $this->logger->info('performance.batch_insert', [
                'table' => $table,
                'count' => count($records),
                'execution_time_ms' => $executionTime
            ]);

            return [
                'ok' => true,
                'inserted' => count($records),
                'execution_time_ms' => $executionTime
            ];
        } catch (\Exception $e) {
            try {
                $this->db->rollBack();
            } catch (\Exception $rollbackError) {
                // transaction already rolled back
            }
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

        if (!$this->isValidIdentifier($table) || !$this->isValidIdentifier($whereColumn)) {
            return ['ok' => false, 'error' => 'نام جدول یا ستون نامعتبر است'];
        }

        $quotedTable = $this->quoteIdentifier($table);
        $quotedWhereColumn = $this->quoteIdentifier($whereColumn);

        try {
            $startTime = microtime(true);
            $this->db->beginTransaction();

            $count = 0;
            foreach ($updates as $record) {
                $whereValue = array_shift($whereValues);
                
                $set = [];
                $values = [];
                foreach ($record as $column => $value) {
                    if (!$this->isValidIdentifier((string)$column)) {
                        throw new \InvalidArgumentException('نام ستون نامعتبر است: ' . $column);
                    }
                    $quotedColumn = $this->quoteIdentifier((string)$column);
                    $set[] = "{$quotedColumn} = ?";
                    $values[] = $value;
                }
                $values[] = $whereValue;

                $sql = "UPDATE {$quotedTable} SET " . implode(', ', $set) . 
                       " WHERE {$quotedWhereColumn} = ?";

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

        // ⚠️ Flag slow queries based on dynamically configured thresholds
        if ($executionTime > $this->slowQueryThreshold * 1000) {
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

    // ──────────────────────────────────────────────────────────────────────────
    // Query Analysis (from QueryOptimizationService)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * تحلیل و اجرای query با مانیتورینگ
     * Execute query with performance monitoring and EXPLAIN analysis
     */
    public function executeWithAnalysis(string $sql, array $params = [], bool $analyze = true): array
    {
        $startTime = microtime(true);
        
        // اجرای query
        $stmt = $this->db->query($sql, $params);
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ) ?? [];
        
        $executionTime = microtime(true) - $startTime;
        
        // لاگ slow query
        if ($executionTime > $this->slowQueryThreshold && $this->logSlowQueries) {
            $this->logSlowQuery($sql, $params, $executionTime);
        }
        
        // تحلیل query اگر خواسته شده
        $analysis = null;
        if ($analyze && $executionTime > $this->slowQueryThreshold) {
            $analysis = $this->analyzeQuery($sql);
        }
        
        return [
            'results' => $results,
            'execution_time' => $executionTime,
            'is_slow' => $executionTime > $this->slowQueryThreshold,
            'analysis' => $analysis,
        ];
    }
    
    /**
     * تحلیل query با EXPLAIN
     * Analyze query execution plan with suggestions
     */
    public function analyzeQuery(string $sql): array
    {
        try {
            // اجرای EXPLAIN - فقط SELECT queries کے لیے
            if (!preg_match('/^\s*SELECT\s+/i', trim($sql))) {
                return [
                    'error' => 'EXPLAIN only works with SELECT queries',
                    'explain' => [],
                    'issues' => [],
                    'suggestions' => [],
                    'needs_optimization' => false,
                ];
            }
            
            // اجرای EXPLAIN
            $explainSql = 'EXPLAIN ' . $sql;
            $stmt = $this->db->query($explainSql);
            $explain = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            
            $issues = [];
            $suggestions = [];
            
            foreach ($explain as $row) {
                // بررسی full table scan
                if (isset($row['type']) && $row['type'] === 'ALL') {
                    $issues[] = "Full table scan on table: {$row['table']}";
                    $suggestions[] = "Consider adding index on: {$row['table']}";
                }
                
                // بررسی filesort
                if (isset($row['Extra']) && strpos($row['Extra'], 'Using filesort') !== false) {
                    $issues[] = "Using filesort (expensive sorting)";
                    $suggestions[] = "Add index to support ORDER BY";
                }
                
                // بررسی temporary table
                if (isset($row['Extra']) && strpos($row['Extra'], 'Using temporary') !== false) {
                    $issues[] = "Using temporary table";
                    $suggestions[] = "Optimize query to avoid temporary tables";
                }
                
                // بررسی rows examined
                if (isset($row['rows']) && $row['rows'] > 10000) {
                    $issues[] = "Examining too many rows: {$row['rows']}";
                    $suggestions[] = "Add more specific WHERE clauses or indexes";
                }
            }
            
            return [
                'explain' => $explain,
                'issues' => $issues,
                'suggestions' => $suggestions,
                'needs_optimization' => !empty($issues),
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error('query.analysis.failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return [
                'error' => 'analysis_failed',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * پیشنهاد index برای یک جدول
     * Suggest indexes for table based on common patterns
     */
    public function suggestIndexes(string $table): array
    {
        $suggestions = [];
        
        // Validate table name
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $table)) {
            return ['error' => 'Invalid table name'];
        }
        
        try {
            // دریافت ستون‌های موجود
            $columns = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            
            // دریافت index های موجود
            $indexes = $this->db->query("SHOW INDEXES FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            $indexedColumns = array_column($indexes, 'Column_name');
            
            // بررسی ستون‌های شایع که نیاز به index دارند
            foreach ($columns as $column) {
                $colName = $column['Field'];
                
                // اگر قبلاً index شده، رد کن
                if (in_array($colName, $indexedColumns, true)) {
                    continue;
                }
                
                $needsIndex = false;
                $reason = '';
                
                // Foreign keys
                if (preg_match('/_id$/', $colName)) {
                    $needsIndex = true;
                    $reason = 'Foreign key column';
                }
                
                // Status fields
                if ($colName === 'status') {
                    $needsIndex = true;
                    $reason = 'Status field (frequently filtered)';
                }
                
                // Date fields
                if (in_array($colName, ['created_at', 'updated_at', 'deleted_at'], true)) {
                    $needsIndex = true;
                    $reason = 'Date field (frequently sorted/filtered)';
                }
                
                // Email/Username
                if (in_array($colName, ['email', 'username', 'mobile'], true)) {
                    $needsIndex = true;
                    $reason = 'Lookup field';
                }
                
                if ($needsIndex) {
                    $suggestions[] = [
                        'table' => $table,
                        'column' => $colName,
                        'reason' => $reason,
                        'sql' => "ALTER TABLE `{$table}` ADD INDEX idx_{$colName} (`{$colName}`);",
                    ];
                }
            }
            
            // پیشنهاد composite indexes
            $compositeIndexes = $this->suggestCompositeIndexes($table);
            $suggestions = array_merge($suggestions, $compositeIndexes);
            
        } catch (\Throwable $e) {
            $this->logger->error('query_optimization.index_suggestion.failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $suggestions;
    }
    
    /**
     * پیشنهاد composite indexes
     */
    private function suggestCompositeIndexes(string $table): array
    {
        $suggestions = [];
        
        // الگوهای شایع composite index
        $patterns = [
            ['user_id', 'status'],
            ['user_id', 'created_at'],
            ['status', 'created_at'],
            ['type', 'status'],
        ];
        
        try {
            $columns = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            $columnNames = array_column($columns, 'Field');
            
            foreach ($patterns as $pattern) {
                // بررسی اینکه هر دو ستون وجود دارند
                if (array_intersect($pattern, $columnNames) === $pattern) {
                    $indexName = 'idx_' . implode('_', $pattern);
                    $columnList = implode(', ', array_map(fn($col) => "`{$col}`", $pattern));
                    
                    $suggestions[] = [
                        'table' => $table,
                        'columns' => $pattern,
                        'reason' => 'Common filtering pattern',
                        'sql' => "ALTER TABLE `{$table}` ADD INDEX {$indexName} ({$columnList});",
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silent
        }
        
        return $suggestions;
    }
    
    /**
     * بررسی سلامت دیتابیس
     * Database health check - finds issues like missing PKs, MyISAM tables, etc.
     */
    public function healthCheck(): array
    {
        $issues = [];
        $recommendations = [];
        
        try {
            // بررسی جداول بدون Primary Key
            $tables = $this->db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN) ?? [];
            
            foreach ($tables as $table) {
                $keys = $this->db->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'")->fetchAll(\PDO::FETCH_ASSOC) ?? [];
                
                if (empty($keys)) {
                    $issues[] = "Table `{$table}` has no primary key";
                    $recommendations[] = "Add primary key to `{$table}`";
                }
            }
            
            // بررسی جداول بزرگ بدون index
            $largeTables = $this->db->query(
                "SELECT TABLE_NAME, TABLE_ROWS 
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_ROWS > 10000"
            )->fetchAll(\PDO::FETCH_ASSOC) ?? [];
            
            foreach ($largeTables as $tableInfo) {
                $table = $tableInfo['TABLE_NAME'];
                $rows = $tableInfo['TABLE_ROWS'];
                
                $indexes = $this->db->query("SHOW INDEXES FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC) ?? [];
                
                // اگر فقط PRIMARY KEY داره
                if (count($indexes) <= 1) {
                    $issues[] = "Large table `{$table}` ({$rows} rows) has minimal indexing";
                    $recommendations[] = "Review and add appropriate indexes to `{$table}`";
                }
            }
            
            // بررسی MyISAM tables
            $myisamTables = $this->db->query(
                "SELECT TABLE_NAME 
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND ENGINE = 'MyISAM'"
            )->fetchAll(\PDO::FETCH_COLUMN) ?? [];
            
            if (!empty($myisamTables)) {
                $issues[] = "Found MyISAM tables: " . implode(', ', $myisamTables);
                $recommendations[] = "Convert MyISAM tables to InnoDB for better performance";
            }
            
        } catch (\Throwable $e) {
            $issues[] = "Health check failed: " . $e->getMessage();
        }
        
        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'recommendations' => $recommendations,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * لاگ slow query
     */
    private function logSlowQuery(string $sql, array $params, float $executionTime): void
    {
        $this->logger->warning('database.slow_query', [
            'sql' => $sql,
            'params' => $params,
            'execution_time_sec' => $executionTime,
            'threshold_sec' => $this->slowQueryThreshold,
        ]);
        
        // لاگ در فایل (اختیاری)
        $logFile = dirname(__DIR__, 2) . '/storage/logs/slow_queries.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logLine = sprintf(
            "[%s] Slow Query (%.3fs): %s\n",
            date('Y-m-d H:i:s'),
            $executionTime,
            $sql
        );
        
        @file_put_contents($logFile, $logLine, FILE_APPEND);
    }
    
    /**
     * دریافت آمار slow queries
     */
    public function getSlowQueryStats(int $limit = 10): array
    {
        try {
            $logFile = dirname(__DIR__, 2) . '/storage/logs/slow_queries.log';
            
            if (!file_exists($logFile)) {
                return [];
            }
            
            // خواندن آخرین لاین‌ها
            $lines = file($logFile) ?: [];
            $lines = array_slice($lines, -$limit);
            
            $stats = [];
            
            foreach ($lines as $line) {
                if (preg_match('/\[([^\]]+)\] Slow Query \(([0-9.]+)s\): (.+)/', $line, $matches)) {
                    $stats[] = [
                        'timestamp' => $matches[1],
                        'execution_time' => (float)$matches[2],
                        'sql' => trim($matches[3]),
                    ];
                }
            }
            
            return array_reverse($stats);
            
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper Methods for Service Integration
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * بهبود یافتہ batch fetch - whereIn() کے ساتھ
     * Fetch multiple records with IDs in bulk (prevent N+1)
     * 
     * @param string $table Table name
     * @param string $idColumn Column name (usually 'id')
     * @param array $ids Array of IDs to fetch
     * @return array Fetched records
     */
    public function bulkFetch(string $table, string $idColumn, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        if (!$this->isValidIdentifier($table) || !$this->isValidIdentifier($idColumn)) {
            $this->logger->error('performance.bulk_fetch.failed', ['error' => 'Invalid identifier']);
            return [];
        }

        try {
            $startTime = microtime(true);
            
            $quotedTable = $this->quoteIdentifier($table);
            $quotedIdColumn = $this->quoteIdentifier($idColumn);
            
            // Build IN clause with placeholders
            $placeholders = array_fill(0, count($ids), '?');
            $sql = "SELECT * FROM {$quotedTable} WHERE {$quotedIdColumn} IN (" . implode(', ', $placeholders) . ")";
            
            $results = $this->db->query($sql, $ids)->fetchAll(\PDO::FETCH_OBJ) ?? [];
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            if ($executionTime > $this->slowQueryThreshold * 1000) {
                $this->logger->warning('performance.bulk_fetch_slow', [
                    'table' => $table,
                    'count' => count($ids),
                    'execution_time_ms' => $executionTime,
                ]);
            }
            
            return $results;
        } catch (\Exception $e) {
            $this->logger->error('performance.bulk_fetch.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Consolidated aggregation query
     * Get multiple aggregates (COUNT, SUM, AVG) in single query
     * 
     * @param string $table Table name
     * @param array $aggregates ['count' => true, 'sum_field' => 'amount', 'avg_field' => 'rating']
     * @param array $where WHERE conditions as key => value
     * @return array Single row with all aggregates
     */
    public function getAggregates(string $table, array $aggregates, array $where = []): array
    {
        if (!$this->isValidIdentifier($table)) {
            $this->logger->error('performance.aggregates.failed', ['error' => 'Invalid table name']);
            return [];
        }

        try {
            $startTime = microtime(true);
            
            $quotedTable = $this->quoteIdentifier($table);
            
            // Build SELECT clause with aggregates
            $selectParts = [];
            if ($aggregates['count'] ?? false) {
                $selectParts[] = "COUNT(*) as total_count";
            }
            
            $aggregateFields = ['sum_field' => 'SUM', 'avg_field' => 'AVG', 'max_field' => 'MAX', 'min_field' => 'MIN'];
            $aliasMap = ['sum_field' => 'total_sum', 'avg_field' => 'average', 'max_field' => 'maximum', 'min_field' => 'minimum'];
            
            foreach ($aggregateFields as $key => $function) {
                if (isset($aggregates[$key])) {
                    $field = $aggregates[$key];
                    if (!$this->isValidIdentifier($field)) {
                        throw new \InvalidArgumentException('نام فیلد تجمعی نامعتبر است');
                    }
                    $quotedField = $this->quoteIdentifier($field);
                    $alias = $aliasMap[$key];
                    $selectParts[] = "{$function}({$quotedField}) as {$alias}";
                }
            }
            
            if (empty($selectParts)) {
                $selectParts[] = "*";
            }
            
            $sql = "SELECT " . implode(', ', $selectParts) . " FROM {$quotedTable}";
            
            // Add WHERE conditions
            if (!empty($where)) {
                $whereConditions = [];
                $params = [];
                foreach ($where as $column => $value) {
                    if (!$this->isValidIdentifier((string)$column)) {
                        throw new \InvalidArgumentException('نام ستون شرط نامعتبر است');
                    }
                    $quotedCol = $this->quoteIdentifier((string)$column);
                    $whereConditions[] = "{$quotedCol} = ?";
                    $params[] = $value;
                }
                $sql .= " WHERE " . implode(' AND ', $whereConditions);
            }
            
            $result = $this->db->query($sql, $params ?? [])->fetch(\PDO::FETCH_ASSOC);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->info('performance.aggregates_query', [
                'table' => $table,
                'execution_time_ms' => $executionTime,
            ]);
            
            return $result ?: [];
        } catch (\Exception $e) {
            $this->logger->error('performance.aggregates.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Batch operation with CASE statement (more efficient than loop)
     * Update multiple rows with different values
     * 
     * @param string $table Table name
     * @param string $idColumn Column to match on
     * @param array $updates Map of id => [column => value]
     * @return array ['ok' => bool, 'updated' => count]
     */
    public function bulkUpdateWithCase(string $table, string $idColumn, array $updates): array
    {
        if (empty($updates)) {
            return ['ok' => false, 'error' => 'هیچ رکورد برای به‌روزرسانی وجود ندارد'];
        }

        if (!$this->isValidIdentifier($table) || !$this->isValidIdentifier($idColumn)) {
            return ['ok' => false, 'error' => 'نام جدول یا ستون نامعتبر است'];
        }

        $quotedTable = $this->quoteIdentifier($table);
        $quotedIdColumn = $this->quoteIdentifier($idColumn);

        try {
            $startTime = microtime(true);
            
            // Get first record to determine columns
            $firstRecord = reset($updates);
            $columns = array_keys($firstRecord);
            
            foreach ($columns as $column) {
                if (!$this->isValidIdentifier((string)$column)) {
                    throw new \InvalidArgumentException('نام ستون نامعتبر است');
                }
            }
            
            // Build CASE statements for each column
            $setClauses = [];
            $ids = array_keys($updates);
            
            foreach ($columns as $column) {
                $quotedColumn = $this->quoteIdentifier((string)$column);
                $caseStatement = "CASE {$quotedIdColumn}";
                foreach ($updates as $id => $record) {
                    // Use ? placeholders for IDs to prevent injection in CASE
                    $caseStatement .= " WHEN ? THEN ?";
                }
                $caseStatement .= " END";
                $setClauses[] = "{$quotedColumn} = {$caseStatement}";
            }
            
            // Build full UPDATE query
            $idPlaceholders = array_fill(0, count($ids), '?');
            $sql = "UPDATE {$quotedTable} SET " . implode(', ', $setClauses) . 
                   " WHERE {$quotedIdColumn} IN (" . implode(', ', $idPlaceholders) . ")";
            
            // Collect all values for CASE statements
            $params = [];
            foreach ($columns as $column) {
                foreach ($updates as $id => $record) {
                    $params[] = $id; // For WHEN ?
                    $params[] = $record[$column]; // For THEN ?
                }
            }
            $params = array_merge($params, $ids); // Add IDs for WHERE clause
            
            $this->db->beginTransaction();
            $stmt = $this->db->query($sql, $params);
            $this->db->commit();
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $this->logger->info('performance.bulk_update_case', [
                'table' => $table,
                'count' => count($updates),
                'execution_time_ms' => $executionTime,
            ]);
            
            return [
                'ok' => true,
                'updated' => count($updates),
                'execution_time_ms' => $executionTime,
            ];
        } catch (\Exception $e) {
            try {
                $this->db->rollBack();
            } catch (\Exception $rollbackError) {
                // already rolled back
            }
            $this->logger->error('performance.bulk_update_case.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function isValidIdentifier(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
    }

    private function quoteIdentifier(string $name): string
    {
        return "`" . str_replace("`", "``", $name) . "`";
    }
}

