<?php

declare(strict_types=1);

namespace App\Services\Sentry\PerformanceMonitoring;

use App\Models\SentryModel;
use Core\Logger;
use App\Services\Sentry\Alerting\AlertDispatcher;

/**
 * 🚀 SentryPerformanceMonitor - مانیتورینگ عملکرد سیستم
 */
class SentryPerformanceMonitor
{
    private ?array $currentTransaction = null;
    private array $spans = [];
    private array $queries = [];
    private float $startTime;
    private int $startMemory;
    
    private array $config = [
        'enabled' => true,
        'sample_rate' => 1.0,
        'slow_threshold' => 1000, // ms
        'memory_threshold' => 50 * 1024 * 1024, // 50MB
    ];

    private SentryModel $model;
    private Logger $logger;
    private AlertDispatcher $alertDispatcher;
    public function __construct(
        SentryModel $model,
        Logger $logger,
        AlertDispatcher $alertDispatcher,
        array $config = []
    ) {        $this->model = $model;
        $this->logger = $logger;
        $this->alertDispatcher = $alertDispatcher;

        $this->config = array_merge($this->config, $config);
        
        // PM1: Calibrate timing back to application bootstrap entry bounds to fully capture request boot cost
        $this->startTime = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * 🎬 Start Transaction - شروع transaction جدید
     */
    public function startTransaction(string $name, string $op = 'http.request', array $data = []): ?string
    {
        if (!$this->config['enabled'] || !$this->shouldSample()) {
            return null;
        }

        $transactionId = $this->generateId();

        $this->currentTransaction = [
            'transaction_id' => $transactionId,
            'name' => $name,
            'op' => $op,
            'start_timestamp' => microtime(true),
            'data' => $data,
            'tags' => [],
        ];

        return $transactionId;
    }

    /**
     * 🏁 Finish Transaction
     */
    public function finishTransaction(array $context = []): void
    {
        if (!$this->currentTransaction) {
            return;
        }

        $duration = (microtime(true) - $this->currentTransaction['start_timestamp']) * 1000;
        $memoryUsed = memory_get_usage(true) - $this->startMemory;
        $peakMemory = memory_get_peak_usage(true);

        $transaction = array_merge($this->currentTransaction, [
            'duration' => $duration,
            'memory_used' => $memoryUsed,
            'peak_memory' => $peakMemory,
            'spans' => $this->spans,
            'queries' => $this->queries,
            'query_count' => count($this->queries),
            'slow_queries_count' => $this->countSlowQueries(),
            'status' => $this->determineStatus($context),
            'context' => $context,
        ]);

        $issues = $this->detectPerformanceIssues($transaction);
        if (!empty($issues)) {
            $transaction['issues'] = $issues;
        }

        $this->storeTransaction($transaction);
        $this->reset();
    }

    /**
     * ⏱️ Start Span
     */
    public function startSpan(string $op, string $description, array $data = []): string
    {
        $spanId = $this->generateId();

        // PM2: Enforce in-memory heap container limits to suppress potential memory allocation leaks
        if (count($this->spans) >= 1000) {
            return $spanId;
        }

        $this->spans[$spanId] = [
            'span_id' => $spanId,
            'op' => $op,
            'description' => $description,
            'start_timestamp' => microtime(true),
            'data' => $data,
        ];

        return $spanId;
    }

    /**
     * ✅ Finish Span
     */
    public function finishSpan(string $spanId, array $data = []): void
    {
        if (!isset($this->spans[$spanId])) {
            return;
        }

        $duration = (microtime(true) - $this->spans[$spanId]['start_timestamp']) * 1000;
        
        $this->spans[$spanId] = array_merge($this->spans[$spanId], [
            'duration' => $duration,
            'data' => array_merge($this->spans[$spanId]['data'], $data),
        ]);

        unset($this->spans[$spanId]['start_timestamp']);
    }

    /**
     * 🗄️ Track Query
     */
    public function trackQuery(string $query, float $duration, ?array $params = null): void
    {
        // PM2: Restrict query logging limit bounds to safeguard system from memory exhaustion
        if (count($this->queries) >= 1000) {
            return;
        }

        $this->queries[] = [
            'query' => $this->sanitizeQuery($query),
            'duration' => $duration,
            'params_count' => $params ? count($params) : 0,
            'is_slow' => $duration > 100,
        ];
    }

    private function detectPerformanceIssues(array $transaction): array
    {
        $issues = [];

        if ($transaction['duration'] > $this->config['slow_threshold']) {
            $issues[] = [
                'type' => 'slow_transaction',
                'severity' => $transaction['duration'] > 3000 ? 'high' : 'medium',
                'message' => 'Transaction took ' . round($transaction['duration']) . 'ms',
                'threshold' => $this->config['slow_threshold'],
                'actual' => $transaction['duration'],
            ];
        }

        $nPlusOnes = $this->detectNPlusOneQueries();
        if (!empty($nPlusOnes)) {
            foreach ($nPlusOnes as $nPlusOne) {
                $issues[] = [
                    'type' => 'n_plus_one_query',
                    'severity' => 'high',
                    'message' => "Detected {$nPlusOne['count']} similar queries",
                    'pattern' => $nPlusOne['pattern'],
                    'count' => $nPlusOne['count'],
                    'total_duration' => $nPlusOne['total_duration'],
                ];
            }
        }

        $slowQueries = $this->getSlowQueries(100);
        if (!empty($slowQueries)) {
            $issues[] = [
                'type' => 'slow_queries',
                'severity' => 'medium',
                'message' => count($slowQueries) . ' slow queries detected',
                'count' => count($slowQueries),
                'queries' => array_slice($slowQueries, 0, 5),
            ];
        }

        $memoryLeak = $this->detectMemoryLeak();
        if ($memoryLeak) {
            $issues[] = $memoryLeak;
        }

        if (count($this->queries) > 50) {
            $issues[] = [
                'type' => 'too_many_queries',
                'severity' => 'medium',
                'message' => count($this->queries) . ' queries in single request',
                'count' => count($this->queries),
            ];
        }

        return $issues;
    }

    private function storeTransaction(array $transaction): void
    {
        try {
            $this->model->storePerformanceTransaction([
                'transaction_id' => $transaction['transaction_id'],
                'name' => $transaction['name'],
                'op' => $transaction['op'],
                'duration' => $transaction['duration'],
                'memory_used' => $transaction['memory_used'],
                'peak_memory' => $transaction['peak_memory'],
                'query_count' => $transaction['query_count'],
                'slow_queries_count' => $transaction['slow_queries_count'],
                'status' => $transaction['status'],
                'spans' => json_encode($transaction['spans']),
                'queries' => json_encode($transaction['queries']),
                'issues' => json_encode($transaction['issues'] ?? []),
                'context' => json_encode($transaction['context']),
            ]);

            if (!empty($transaction['issues'])) {
                $this->handlePerformanceAlert($transaction);
            }

        } catch (\Throwable $e) {
            $this->logger->error('Failed to store transaction', ['error' => $e->getMessage()]);
        }
    }

    private function handlePerformanceAlert(array $transaction): void
    {
        foreach ($transaction['issues'] as $issue) {
            if ($issue['severity'] === 'high') {
                $this->alertDispatcher->dispatch([
                    'type' => 'performance',
                    'severity' => $issue['severity'],
                    'title' => 'Performance Issue: ' . $issue['type'],
                    'message' => $issue['message'],
                    'metadata' => [
                        'transaction' => $transaction['name'],
                        'duration' => $transaction['duration'],
                        'issue_type' => $issue['type'],
                    ],
                ]);
            }
        }
    }

    private function sanitizeQuery(string $query): string
    {
        $sanitized = preg_replace('/\bVALUES\s*\([^)]+\)/i', 'VALUES (?)', $query);
        $sanitized = preg_replace('/= ?\?/', '= ?', $sanitized);
        return substr($sanitized, 0, 500);
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function shouldSample(): bool
    {
        return (mt_rand() / mt_getrandmax()) <= $this->config['sample_rate'];
    }

    private function countSlowQueries(): int
    {
        return count(array_filter($this->queries, fn($q) => $q['is_slow']));
    }

    private function determineStatus(array $context): string
    {
        $statusCode = $context['status_code'] ?? 200;
        if ($statusCode >= 500) return 'internal_error';
        if ($statusCode >= 400) return 'invalid_argument';
        if ($statusCode >= 300) return 'redirect';
        return 'ok';
    }

    private function reset(): void
    {
        $this->currentTransaction = null;
        $this->spans = [];
        $this->queries = [];
    }

    public function getStatistics(string $period = 'today'): array
    {
        $stats = $this->model->getPerformanceAggregates($period);

        return [
            'total_transactions' => $stats->total_transactions ?? 0,
            'avg_duration' => round((float)($stats->avg_duration ?? 0), 2),
            'max_duration' => round((float)($stats->max_duration ?? 0), 2),
            'avg_queries' => round((float)($stats->avg_queries ?? 0), 2),
            'transactions_with_slow_queries' => $stats->transactions_with_slow_queries ?? 0,
            'avg_memory_mb' => round(((float)($stats->avg_memory ?? 0)) / 1024 / 1024, 2),
        ];
    }

    public function getSlowestTransactions(int $limit = 10): array
    {
        return $this->model->getSlowestTransactions($limit);
    }

    private function detectNPlusOneQueries(): array
    {
        $similarQueries = [];
        foreach ($this->queries as $query) {
            $pattern = $this->getQueryPattern($query['query']);
            if (!isset($similarQueries[$pattern])) {
                $similarQueries[$pattern] = [];
            }
            $similarQueries[$pattern][] = $query;
        }

        $nPlusOnes = [];
        foreach ($similarQueries as $pattern => $queries) {
            if (count($queries) > 5) {
                $nPlusOnes[] = [
                    'pattern' => $pattern,
                    'count' => count($queries),
                    'total_duration' => array_sum(array_column($queries, 'duration')),
                ];
            }
        }
        return $nPlusOnes;
    }

    private function getQueryPattern(string $query): string
    {
        // PM3: Strengthen query pattern normalizer using strict multi-scanner regex heuristics
        
        // 1. Standardize string literal values (both double and single quoted literals)
        $pattern = preg_replace('/([\'"])(.*?)(?<!\\\\)\1/', '?', $query);

        // 2. Consolidate multiple numeric entries inside IN () structures into a singular symbol
        $pattern = preg_replace('/\bIN\s*\([^)]*\)/i', 'IN (?)', $pattern ?: '');

        // 3. Convert all remaining floating points and integer values to parameter tokens
        $pattern = preg_replace('/\b\d+(\.\d+)?\b/', '?', $pattern ?: '');

        // 4. Compress whitespace layouts down to standardized spaces
        $pattern = preg_replace('/\s+/', ' ', $pattern ?: '');

        return trim((string)$pattern);
    }

    private function getSlowQueries(float $threshold = 100): array
    {
        return array_filter($this->queries, fn($q) => $q['duration'] > $threshold);
    }

    private function detectMemoryLeak(): ?array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryGrowth = $currentMemory - $this->startMemory;

        if ($memoryGrowth > $this->config['memory_threshold']) {
            return [
                'type' => 'memory_leak',
                'severity' => 'high',
                'start_memory' => $this->startMemory,
                'current_memory' => $currentMemory,
                'peak_memory' => $peakMemory,
                'growth' => $memoryGrowth,
                'growth_mb' => round($memoryGrowth / 1024 / 1024, 2),
            ];
        }
        return null;
    }
}
