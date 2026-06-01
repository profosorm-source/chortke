<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use App\Contracts\LoggerInterface;

/**
 * PerformanceOptimizationService
 * 
 * Consolidated service combining performance monitoring and batch operations.
 * 
 * Features:
 * - Batch insert/update operations (large datasets)
 * - Query execution time tracking & monitoring
 * - Slow query detection & logging
 * - Query performance statistics
 * - Data aggregation
 */
class PerformanceOptimizationService
{

    private array $queryTimes = [];
    private int $queryCount = 0;
    private float $slowQueryThreshold = 1.0; // seconds
    private bool $logSlowQueries = true;

    private \Core\Database $db;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \Core\Database $db,
        \App\Contracts\LoggerInterface $logger
    )
    {        $this->db = $db;
        $this->logger = $logger;

        
        $this->slowQueryThreshold = (float)config('logging.performance.slow_query_threshold', 1.0);
        $this->logSlowQueries = (bool)config('logging.performance.log_slow_queries', true);
    }

    public function batchInsert(string $table, array $records): array
    {
        if (empty($records)) return ['ok' => false, 'error' => 'هیچ رکورد برای درج وجود ندارد'];
        if (!$this->isValidIdentifier($table)) return ['ok' => false, 'error' => 'نام جدول نامعتبر است'];

        try {
            $startTime = microtime(true);
            $columns = array_keys($records[0]);
            foreach ($columns as $column) {
                if (!$this->isValidIdentifier((string)$column)) return ['ok' => false, 'error' => 'نام ستون نامعتبر است'];
            }

            $quotedTable = $this->quoteIdentifier($table);
            $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
            $columnList = implode(', ', $quotedColumns);
            
            $valueParts = [];
            $allValues = [];
            
            foreach ($records as $record) {
                $placeholders = array_fill(0, count($columns), '?');
                $valueParts[] = '(' . implode(', ', $placeholders) . ')';
                $allValues = array_merge($allValues, array_values($record));
            }
            
            $sql = "INSERT INTO {$quotedTable} ({$columnList}) VALUES " . implode(', ', $valueParts);
            
            $this->db->beginTransaction();
            $this->db->query($sql, $allValues);
            $this->db->commit();

            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->logger->info('performance.batch_insert', ['table' => $table, 'count' => count($records), 'execution_time_ms' => $executionTime]);
            return ['ok' => true, 'inserted' => count($records), 'execution_time_ms' => $executionTime];
        } catch (\Exception $e) {
            try { $this->db->rollBack(); } catch (\Exception $rollbackError) {}
            $this->logger->error('performance.batch_insert.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function batchUpdate(string $table, array $updates, string $whereColumn, array $whereValues): array
    {
        if (empty($updates) || empty($whereValues)) return ['ok' => false, 'error' => 'Invalid parameters'];
        if (!$this->isValidIdentifier($table) || !$this->isValidIdentifier($whereColumn)) return ['ok' => false, 'error' => 'نام جدول یا ستون نامعتبر است'];

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
                    if (!$this->isValidIdentifier((string)$column)) throw new \InvalidArgumentException('نام ستون نامعتبر است: ' . $column);
                    $quotedColumn = $this->quoteIdentifier((string)$column);
                    $set[] = "{$quotedColumn} = ?";
                    $values[] = $value;
                }
                $values[] = $whereValue;

                $sql = "UPDATE {$quotedTable} SET " . implode(', ', $set) . " WHERE {$quotedWhereColumn} = ?";
                $this->db->query($sql, $values);
                $count++;
            }

            $this->db->commit();
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->logger->info('performance.batch_update', ['table' => $table, 'count' => $count, 'execution_time_ms' => $executionTime]);
            return ['ok' => true, 'updated' => $count, 'execution_time_ms' => $executionTime];
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('performance.batch_update.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function bulkUpdateWithCase(string $table, string $idColumn, array $updates): array
    {
        if (empty($updates)) return ['ok' => false, 'error' => 'هیچ رکورد برای به‌روزرسانی وجود ندارد'];
        if (!$this->isValidIdentifier($table) || !$this->isValidIdentifier($idColumn)) return ['ok' => false, 'error' => 'نام جدول یا ستون نامعتبر است'];

        $quotedTable = $this->quoteIdentifier($table);
        $quotedIdColumn = $this->quoteIdentifier($idColumn);

        try {
            $startTime = microtime(true);
            $firstRecord = reset($updates);
            $columns = array_keys($firstRecord);
            foreach ($columns as $column) {
                if (!$this->isValidIdentifier((string)$column)) throw new \InvalidArgumentException('نام ستون نامعتبر است');
            }
            
            $setClauses = [];
            $ids = array_keys($updates);
            $params = [];
            
            foreach ($columns as $column) {
                $quotedColumn = $this->quoteIdentifier((string)$column);
                $caseStatement = "CASE {$quotedIdColumn}";
                foreach ($updates as $id => $record) {
                    $caseStatement .= " WHEN ? THEN ?";
                    $params[] = $id;
                    $params[] = $record[$column];
                }
                $caseStatement .= " END";
                $setClauses[] = "{$quotedColumn} = {$caseStatement}";
            }
            
            $idPlaceholders = array_fill(0, count($ids), '?');
            $sql = "UPDATE {$quotedTable} SET " . implode(', ', $setClauses) . " WHERE {$quotedIdColumn} IN (" . implode(', ', $idPlaceholders) . ")";
            $params = array_merge($params, $ids);
            
            $this->db->beginTransaction();
            $this->db->query($sql, $params);
            $this->db->commit();
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->logger->info('performance.bulk_update_case', ['table' => $table, 'count' => count($updates), 'execution_time_ms' => $executionTime]);
            return ['ok' => true, 'updated' => count($updates), 'execution_time_ms' => $executionTime];
        } catch (\Exception $e) {
            try { $this->db->rollBack(); } catch (\Exception $rollbackError) {}
            $this->logger->error('performance.bulk_update_case.failed', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function bulkFetch(string $table, string $idColumn, array $ids): array
    {
        if (empty($ids)) return [];
        if (!$this->isValidIdentifier($table) || !$this->isValidIdentifier($idColumn)) return [];

        try {
            $startTime = microtime(true);
            $quotedTable = $this->quoteIdentifier($table);
            $quotedIdColumn = $this->quoteIdentifier($idColumn);
            $placeholders = array_fill(0, count($ids), '?');
            $sql = "SELECT * FROM {$quotedTable} WHERE {$quotedIdColumn} IN (" . implode(', ', $placeholders) . ")";
            
            $results = $this->db->query($sql, array_values($ids))->fetchAll(\PDO::FETCH_OBJ) ?? [];
            $executionTime = microtime(true) - $startTime;
            
            if ($executionTime > $this->slowQueryThreshold) {
                $this->logger->warning('performance.bulk_fetch_slow', ['table' => $table, 'count' => count($ids)]);
            }
            return $results;
        } catch (\Exception $e) {
            $this->logger->error('performance.bulk_fetch.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAggregates(string $table, array $aggregates, array $where = []): array
    {
        if (!$this->isValidIdentifier($table)) return [];

        try {
            $startTime = microtime(true);
            $quotedTable = $this->quoteIdentifier($table);
            $selectParts = [];
            if ($aggregates['count'] ?? false) $selectParts[] = "COUNT(*) as total_count";
            
            $aggregateFields = ['sum_field' => 'SUM', 'avg_field' => 'AVG', 'max_field' => 'MAX', 'min_field' => 'MIN'];
            $aliasMap = ['sum_field' => 'total_sum', 'avg_field' => 'average', 'max_field' => 'maximum', 'min_field' => 'minimum'];
            
            foreach ($aggregateFields as $key => $function) {
                if (isset($aggregates[$key])) {
                    $field = $aggregates[$key];
                    if ($this->isValidIdentifier($field)) {
                        $selectParts[] = "{$function}(" . $this->quoteIdentifier($field) . ") as {$aliasMap[$key]}";
                    }
                }
            }
            if (empty($selectParts)) $selectParts[] = "*";
            
            $sql = "SELECT " . implode(', ', $selectParts) . " FROM {$quotedTable}";
            $params = [];
            
            if (!empty($where)) {
                $whereConditions = [];
                foreach ($where as $column => $value) {
                    if ($this->isValidIdentifier((string)$column)) {
                        $whereConditions[] = $this->quoteIdentifier((string)$column) . " = ?";
                        $params[] = $value;
                    }
                }
                $sql .= " WHERE " . implode(' AND ', $whereConditions);
            }
            
            return $this->db->query($sql, $params)->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            $this->logger->error('performance.aggregates.failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function trackQueryTime(string $query, float $executionTime): void
    {
        $this->queryTimes[] = [
            'query' => $query,
            'time_ms' => $executionTime,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $this->queryCount++;

        if ($executionTime > $this->slowQueryThreshold * 1000) {
            $this->logger->warning('performance.slow_query', [
                'query' => substr($query, 0, 100),
                'time_ms' => $executionTime
            ]);
        }
    }

    public function getQueryStats(): array
    {
        if (empty($this->queryTimes)) {
            return ['total_queries' => 0, 'average_time_ms' => 0, 'slowest_query' => null];
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
            'query_log' => array_slice($this->queryTimes, -10)
        ];
    }

    public function clearQueryLog(): void
    {
        $this->queryTimes = [];
        $this->queryCount = 0;
    }

    private function logSlowQuery(string $sql, array $params, float $executionTime): void
    {
        $this->logger->warning('database.slow_query', [
            'sql' => $sql,
            'params' => $params,
            'execution_time_sec' => $executionTime,
            'threshold_sec' => $this->slowQueryThreshold,
        ]);
        
        $logFile = dirname(__DIR__, 2) . '/storage/logs/slow_queries.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logLine = sprintf("[%s] Slow Query (%.3fs): %s\n", date('Y-m-d H:i:s'), $executionTime, $sql);
        @file_put_contents($logFile, $logLine, FILE_APPEND);
    }

    public function getSlowQueryStats(int $limit = 10): array
    {
        try {
            $logFile = dirname(__DIR__, 2) . '/storage/logs/slow_queries.log';
            if (!file_exists($logFile)) return [];
            $lines = file($logFile) ?: [];
            $lines = array_slice($lines, -$limit);
            $stats = [];
            foreach ($lines as $line) {
                if (preg_match('/\[([^\]]+)\] Slow Query \(([0-9.]+)s\): (.+)/', $line, $matches)) {
                    $stats[] = ['timestamp' => $matches[1], 'execution_time' => (float)$matches[2], 'sql' => trim($matches[3])];
                }
            }
            return array_reverse($stats);
        } catch (\Throwable $e) {
            return [];
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