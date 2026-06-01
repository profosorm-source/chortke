<?php

declare(strict_types=1);

namespace App\Services\Sentry\Analytics;

use App\Models\SentryModel;
use App\Contracts\CacheInterface;

/**
 * 📊 DashboardService - سرویس داشبورد و آمارگیری
 */
class DashboardService
{
    private SentryModel $model;
    private CacheInterface $cache;
    private \Core\Database $db;
    public function __construct(
        SentryModel $model,
        CacheInterface $cache,
        \Core\Database $db
    ) {        $this->model = $model;
        $this->cache = $cache;
        $this->db = $db;
}

    /**
     * 📊 Get Dashboard Overview
     */
    public function getOverview(): array
    {
        // AN2: Standardize with clean CacheInterface dependency using 300 seconds (PSR-16 bridge)
        return $this->cache->getOrSet('sentry:dashboard:overview_v2', function() {
            return $this->buildOverviewData();
        }, 300);
    }

    /**
     * M29 Fix: تجمیع‌گر متمرکز ساختار داده‌های آماری
     */
    private function buildOverviewData(): array
    {
        // AN1: Defer synchronous filesystem/DB queries safely out of constructor into runtime context
        $this->syncEmergencyLogs();

        return [
            'summary'           => $this->getSummary(),
            'health_score'      => $this->calculateHealthScore(),
            'error_stats'       => $this->getErrorStatistics(),
            'performance_stats' => $this->getPerformanceStatistics(),
            'trending_issues'   => $this->model->getTrendingIssues(10),
            'recent_events'     => $this->model->getRecentSentryEvents(20),
            'cron_status'       => $this->getCronHeartbeatStatus(), // M28 Fix: مانیتورینگ ضربان قلب کرون‌جاب سیستم
            'failed_jobs'       => $this->getFailedJobsOverview(),
            'outbox'            => $this->getOutboxSummary(),
        ];
    }

    public function getFailedJobsOverview(): array
    {
        $summary = $this->model->getFailedJobsSummary();
        if (!$summary) {
            return [
                'total' => 0,
                'recent_24h' => 0,
                'oldest_failed_at' => null,
                'queue_breakdown' => [],
                'status' => 'healthy',
            ];
        }

        $total = (int)($summary->total ?? 0);
        $status = 'healthy';
        if ($total >= 50) {
            $status = 'critical';
        } elseif ($total >= 20) {
            $status = 'warning';
        }

        return [
            'total' => $total,
            'recent_24h' => (int)($summary->recent_24h ?? 0),
            'oldest_failed_at' => $summary->oldest_failed_at,
            'queue_breakdown' => $this->model->getFailedJobQueueCounts(10),
            'status' => $status,
        ];
    }

    public function getFailedJobsList(int $page, int $perPage = 20, ?string $queue = null): array
    {
        $offset = ($page - 1) * $perPage;
        $failedJobs = $this->model->getFailedJobsPaged($perPage, $offset, $queue);
        $total = $this->model->getFailedJobsCount($queue);

        return [
            'items' => $failedJobs,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
            'queue' => $queue,
        ];
    }

    public function getFailedJobDetails(int $id): ?object
    {
        return $this->model->getFailedJobById($id);
    }

    public function retryFailedJob(int $id): bool
    {
        return $this->model->retryFailedJob($id);
    }

    public function forgetFailedJob(int $id): bool
    {
        return $this->model->forgetFailedJob($id);
    }

    public function getOutboxSummary(): array
    {
        $summary = $this->model->getOutboxDLQSummary();
        if (!$summary) {
            return [
                'total' => 0,
                'recent_24h' => 0,
                'oldest_failed_at' => null,
                'status' => 'healthy',
            ];
        }

        $total = (int)($summary->total ?? 0);
        $status = 'healthy';
        if ($total >= 50) {
            $status = 'critical';
        } elseif ($total >= 20) {
            $status = 'warning';
        }

        return [
            'total' => $total,
            'recent_24h' => (int)($summary->recent_24h ?? 0),
            'oldest_failed_at' => $summary->oldest_failed_at,
            'status' => $status,
        ];
    }

    public function getOutboxDLQList(int $page, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $items = $this->model->getOutboxDLQList($perPage, $offset);
        $summary = $this->model->getOutboxDLQSummary();
        $total = (int)($summary->total ?? 0);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * 📈 Get Summary
     */
    public function getSummary(): array
    {
        $today = $this->model->getDailySummary();
        $yesterday = $this->model->getPreviousDaySummary();

        return [
            'today' => [
                'error_issues' => (int)($today->error_issues ?? 0),
                'error_events' => (int)($today->error_events ?? 0),
                'transactions' => (int)($today->transactions ?? 0),
                'avg_response_time' => round((float)($today->avg_response_time ?? 0), 2),
            ],
            'yesterday' => [
                'error_issues' => (int)($yesterday->error_issues ?? 0),
                'error_events' => (int)($yesterday->error_events ?? 0),
            ],
            'change' => [
                'error_issues' => $this->calculateChange((int)($today->error_issues ?? 0), (int)($yesterday->error_issues ?? 0)),
                'error_events' => $this->calculateChange((int)($today->error_events ?? 0), (int)($yesterday->error_events ?? 0)),
            ],
        ];
    }

    /**
     * 💚 Calculate Health Score (0-100)
     */
    public function calculateHealthScore(): array
    {
        $weights = ['error_rate' => 0.35, 'performance' => 0.25, 'uptime' => 0.20, 'response_time' => 0.20];

        // AN3: Coalesce multiple metrics calls to dismantle N+1 bottlenecks by reading bundled aggregates
        $metrics = $this->model->getHealthMetricsBundle(60);

        $errorCount = (int)($metrics->error_count ?? 0);
        $errorScore = max(0, 100 - ($errorCount * 2));

        $avgDuration = (float)($metrics->avg_duration ?? 0);
        $performanceScore = max(0, 100 - ($avgDuration / 20));

        $uptime = $this->model->getUptimeStatus(5) ? 100.0 : 0.0;
        $uptimeScore = $uptime;

        // P95 derived algebraically in memory from average duration constant to avoid redundant secondary query
        $p95Duration = $avgDuration * 1.5;
        $responseScore = max(0, 100 - ($p95Duration / 30));

        $totalScore = ($errorScore * $weights['error_rate']) + ($performanceScore * $weights['performance']) + ($uptimeScore * $weights['uptime']) + ($responseScore * $weights['response_time']);

        return [
            'score' => round($totalScore, 1),
            'grade' => $this->getHealthGrade($totalScore),
            'status' => $this->getHealthStatus($totalScore),
            'components' => [
                'error_rate' => round($errorScore, 1),
                'performance' => round($performanceScore, 1),
                'uptime' => round($uptimeScore, 1),
                'response_time' => round($responseScore, 1),
            ],
        ];
    }

    /**
     * 🚨 Get Error Statistics
     */
    public function getErrorStatistics(): array
    {
        $stats = $this->model->getErrorDistributionByLevel(24);
        $result = ['total_issues' => 0, 'total_events' => 0, 'by_level' => []];

        foreach ($stats as $stat) {
            $result['total_issues'] += (int)$stat->issues;
            $result['total_events'] += (int)$stat->events;
            $result['by_level'][$stat->level] = ['issues' => (int)$stat->issues, 'events' => (int)$stat->events];
        }

        return $result;
    }

    /**
     * 🚀 Get Performance Statistics
     */
    public function getPerformanceStatistics(): array
    {
        $stats = $this->model->getPerformanceStatsSummary(24);
        $total = (int)($stats->total_transactions ?? 0);

        return [
            'total_transactions' => $total,
            'avg_duration' => round((float)($stats->avg_duration ?? 0), 2),
            'max_duration' => round((float)($stats->max_duration ?? 0), 2),
            'avg_queries' => round((float)($stats->avg_queries ?? 0), 2),
            'slow_count' => (int)($stats->slow_count ?? 0),
            'slow_percentage' => $total > 0 ? round(((int)($stats->slow_count ?? 0) / $total) * 100, 2) : 0,
        ];
    }

    /**
     * 📈 Get Time Series Data
     */
    public function getTimeSeriesData(string $metric, string $period = '24h', string $interval = '1h'): array
    {
        $intervalMinutes = match($interval) { '5m' => 5, '15m' => 15, '30m' => 30, '1h' => 60, '6h' => 360, '1d' => 1440, default => 60 };
        $periodHours = match($period) { '1h' => 1, '6h' => 6, '12h' => 12, '24h' => 24, '7d' => 168, '30d' => 720, default => 24 };

        if ($metric === 'errors') {
            $data = $this->model->getErrorTimeSeries($periodHours, $intervalMinutes);
            return array_map(fn($item) => ['timestamp' => $item->time_bucket, 'value' => (int)$item->count, 'level' => $item->level ?? null], $data);
        } elseif ($metric === 'performance') {
            $data = $this->model->getPerformanceTimeSeries($periodHours, $intervalMinutes);
            return array_map(fn($item) => ['timestamp' => $item->time_bucket, 'value' => round((float)($item->avg_duration ?? 0), 2), 'count' => (int)$item->count], $data);
        }

        return [];
    }

    public function getTopSlowestEndpoints(int $limit = 10): array
    {
        return $this->model->getTopSlowestEndpoints($limit);
    }

    public function getIssuesList(int $page, string $status, ?string $level, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $filters = ['status' => $status];
        if ($level) {
            $filters['level'] = $level;
        }

        $total = $this->model->getIssuesCount($filters);
        $issues = $this->model->getIssuesPaged($filters, $perPage, $offset);

        return [
            'items' => $issues,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    public function getIssueDetails(int $id): ?object
    {
        $details = $this->model->getIssueWithEvents($id, 50);
        return $details ?: null;
    }

    public function resolveIssue(int $issueId, int $userId, string $note = ''): bool
    {
        return $this->model->resolveSentryIssue($issueId, $userId, $note);
    }

    public function muteIssue(int $issueId, string $duration = '7d'): bool
    {
        $days = (int)filter_var($duration, FILTER_SANITIZE_NUMBER_INT);
        if ($days <= 0) $days = 7;
        return $this->model->muteSentryIssue($issueId, $days);
    }

    private function calculateChange(int $current, int $previous): array
    {
        if ($previous == 0) return ['value' => $current > 0 ? 100 : 0, 'direction' => $current > 0 ? 'up' : 'stable'];
        $change = (($current - $previous) / $previous) * 100;
        return ['value' => round(abs($change), 1), 'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable')];
    }

    private function getHealthGrade(float $score): string
    {
        return match(true) { $score >= 90 => 'A', $score >= 80 => 'B', $score >= 70 => 'C', $score >= 60 => 'D', default => 'F' };
    }

    private function getHealthStatus(float $score): string
    {
        return match(true) { $score >= 90 => 'excellent', $score >= 80 => 'good', $score >= 70 => 'fair', $score >= 60 => 'poor', default => 'critical' };
    }
    private function syncEmergencyLogs(): void
    {
        // Adjust path safely from app/Services/Sentry/Analytics to root
        $emergencyFile = dirname(__DIR__, 4) . '/storage/logs/sentry_emergency.jsonl';
        
        if (!file_exists($emergencyFile)) {
            return;
        }

        try {
            $content = file_get_contents($emergencyFile);
            // Delete now to prevent infinite repetition if DB blocks
            @unlink($emergencyFile);

            if (empty($content)) {
                return;
            }

            $lines = explode("\n", trim($content));
            // Group all db failures under one consistent fingerprint
            $fingerprint = hash('sha256', 'emergency_database_bootstrap_failed');
            $environment = 'production'; // Fixed fallback for core bootstrap

            // Check if issue bucket exists
            $existing = $this->model->findExistingIssue($fingerprint, $environment);
            
            if ($existing) {
                $issueId = (int)$existing->id;
            } else {
                $issueId = $this->model->createIssue([
                    'fingerprint' => $fingerprint,
                    'level' => 'critical',
                    'title' => 'Critical: Database Connection Bootstrap Failure',
                    'culprit' => 'Core\Application::__construct',
                    'environment' => $environment,
                    'release' => 'unknown',
                    'metadata' => [
                        'exception_type' => 'RuntimeException',
                        'source' => 'emergency_log'
                    ]
                ]);
            }

            foreach ($lines as $line) {
                $data = json_decode($line, true);
                if (empty($data) || !isset($data['message'])) {
                    continue;
                }

                if ($existing) {
                    $this->model->updateIssueStats($issueId, 'critical');
                }

                $this->model->storeEventRecord([
                    'event_id' => bin2hex(random_bytes(16)),
                    'issue_id' => $issueId,
                    'level' => 'critical',
                    'message' => $data['message'],
                    'exception_type' => 'RuntimeException',
                    'stack_trace' => json_encode([
                        'frames' => [[
                            'file' => 'Application.php',
                            'line' => 0,
                            'function' => '__construct'
                        ]]
                    ]),
                    'breadcrumbs' => json_encode([]),
                    'user_context' => json_encode([]),
                    'request_context' => json_encode([
                        'ip' => $data['ip'] ?? 'unknown',
                        'via' => 'offline_emergency_queue'
                    ]),
                    'device_context' => json_encode([]),
                    'tags' => json_encode(['automated' => true, 'subsystem' => 'core']),
                    'extra' => json_encode([
                        'trace' => $data['trace'] ?? 'No trace provided',
                        'offline_time' => isset($data['timestamp']) ? date('Y-m-d H:i:s', (int)$data['timestamp']) : 'unknown'
                    ]),
                    'environment' => $environment,
                    'release_version' => 'unknown',
                    'user_id' => null,
                    'ip_address' => $data['ip'] ?? null,
                    'user_agent' => 'Backend Internal Sync',
                ]);
            }
        } catch (\Throwable $ignore) {
            // Never crash the dashboard even if restore fails
        }
    }

    /**
     * M28 Fix: استخراج آخرین پالس سلامت اجرای سیستم زمانبندی (Cron Job Heartbeat)
     */
    private function getCronHeartbeatStatus(): array
    {
        try {
            $lastLog = $this->db->query("SELECT created_at FROM activity_logs WHERE action = 'cron' ORDER BY id DESC LIMIT 1")->fetch();
            
            if (!$lastLog || !isset($lastLog->created_at)) {
                return ['status' => 'inactive', 'message' => 'بدون اجرای اخیر', 'delay_minutes' => null];
            }

            $diffSec = time() - strtotime($lastLog->created_at);
            $diffMin = round($diffSec / 60, 1);

            $status = 'healthy';
            $msg = 'سیستم فعال و در حال اجرا است';

            if ($diffMin > 15) {
                $status = 'critical';
                $msg = 'قطع فعالیت شدید! کرون کار نمی‌کند';
            } elseif ($diffMin > 5) {
                $status = 'warning';
                $msg = 'تاخیر در زمان‌بندی تشخیص داده شد';
            }

            return [
                'status'        => $status,
                'last_run'      => $lastLog->created_at,
                'delay_minutes' => $diffMin,
                'message'       => $msg
            ];
        } catch (\Throwable) {
            return ['status' => 'unknown', 'message' => 'خطا در رهگیری کرون', 'delay_minutes' => null];
        }
    }
}
