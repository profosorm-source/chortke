<?php

namespace App\Services;

use Core\Database;

use App\Contracts\LoggerInterface;
/**
 * Query Optimization Service
 * 
 * سرویس بهینه‌سازی و مانیتورینگ queryها
 * شناسایی slow queries و پیشنهاد index
 */
class QueryOptimizationService extends \App\Services\BaseService
{
    private Database $db;
    private float $slowQueryThreshold = 1.0; // ثانیه
    private bool $logSlowQueries = true;
    
    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->slowQueryThreshold = (float)env('SLOW_QUERY_THRESHOLD', 1.0);
        $this->logSlowQueries = (bool)env('LOG_SLOW_QUERIES', true);
    }
    
    /**
     * تحلیل و اجرای query با مانیتورینگ
     */
    public function execute(string $sql, array $params = [], bool $analyze = true): array
    {
        $startTime = microtime(true);
        
        // اجرای query
        $stmt = $this->db->query($sql, $params);
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);
        
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
            
            // اجرای EXPLAIN - بدون string concatenation
            $explainSql = 'EXPLAIN ' . $sql;
            $stmt = $this->db->query($explainSql);
            $explain = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            $issues = [];
            $suggestions = [];
            
            foreach ($explain as $row) {
                // بررسی full table scan
                if ($row['type'] === 'ALL') {
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
        'channel' => 'search',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    return [
        'error' => 'internal_error',
    ];
}
    }
    
    /**
     * پیشنهاد index برای یک جدول
     */
    public function suggestIndexes(string $table): array
    {
        $suggestions = [];
        
        // Validate table name - only alphanumeric, underscore, hyphen
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $table)) {
            return ['error' => 'Invalid table name'];
        }
        
        try {
            // دریافت ستون‌های موجود - escape table name
            $columns = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
            
            // دریافت index های موجود - escape table name
            $indexes = $this->db->query("SHOW INDEXES FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
            $indexedColumns = array_column($indexes, 'Column_name');
            
            // بررسی ستون‌های شایع که نیاز به index دارند
            foreach ($columns as $column) {
                $colName = $column['Field'];
                
                // اگر قبلاً index شده، رد کن
                if (in_array($colName, $indexedColumns, true)) {
                    continue;
                }
                
                // ستون‌های شایع که معمولاً نیاز به index دارند
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
                
                // Email/Username (for lookups)
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
    'channel' => 'database',
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
        
        $columns = $this->db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(\PDO::FETCH_ASSOC);
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
        
        return $suggestions;
    }
    
    /**
     * بررسی سلامت دیتابیس
     */
    public function healthCheck(): array
    {
        $issues = [];
        $recommendations = [];
        
        try {
            // بررسی جداول بدون Primary Key
            $tables = $this->db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                $keys = $this->db->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'")->fetchAll();
                
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
            )->fetchAll(\PDO::FETCH_ASSOC);
            
            foreach ($largeTables as $tableInfo) {
                $table = $tableInfo['TABLE_NAME'];
                $rows = $tableInfo['TABLE_ROWS'];
                
                $indexes = $this->db->query("SHOW INDEXES FROM `{$table}`")->fetchAll();
                
                // اگر فقط PRIMARY KEY داره
                if (count($indexes) <= 1) {
                    $issues[] = "Large table `{$table}` ({$rows} rows) has minimal indexing";
                    $recommendations[] = "Review and add appropriate indexes to `{$table}`";
                }
            }
            
            // بررسی MyISAM tables (باید InnoDB باشن)
            $myisamTables = $this->db->query(
                "SELECT TABLE_NAME 
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND ENGINE = 'MyISAM'"
            )->fetchAll(\PDO::FETCH_COLUMN);
            
            if (!empty($myisamTables)) {
                $issues[] = "Found MyISAM tables: " . implode(', ', $myisamTables);
                $recommendations[] = "Convert MyISAM tables to InnoDB for better performance and ACID compliance";
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
        $log = [
            'sql' => $sql,
            'params' => $params,
            'execution_time' => $executionTime,
            'timestamp' => date('Y-m-d H:i:s'),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
        ];
        
        // لاگ در فایل
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
        
        file_put_contents($logFile, $logLine, FILE_APPEND);
        
        // لاگ در دیتابیس (اختیاری)
        try {
            $this->db->query(
                "INSERT INTO slow_query_logs (sql, params, execution_time, created_at) 
                 VALUES (?, ?, ?, NOW())",
                [$sql, json_encode($params), $executionTime]
            );
        } catch (\Throwable $e) {
            // اگر جدول وجود نداره، بی‌خیال
        }
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
            $lines = file($logFile);
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
}

