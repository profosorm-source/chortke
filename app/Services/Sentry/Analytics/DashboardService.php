<?php

declare(strict_types=1);

namespace App\Services\Sentry\Analytics;

use App\Models\SentryModel;

/**
 * 📊 DashboardService - سرویس داشبورد و آمارگیری
 */
class DashboardService
{
    public function __construct(private SentryModel $model) {}

    /**
     * 📊 Get Dashboard Overview
     */
    public function getOverview(): array
    {
        return [
            'summary' => $this->getSummary(),
            'health_score' => $this->calculateHealthScore(),
            'error_stats' => $this->getErrorStatistics(),
            'performance_stats' => $this->getPerformanceStatistics(),
            'trending_issues' => $this->model->getTrendingIssues(10),
            'recent_events' => $this->model->getRecentSentryEvents(20),
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

        $errorCount = $this->model->getMetricValue('error_count', 60);
        $errorScore = max(0, 100 - ($errorCount * 2));

        $avgDuration = $this->model->getMetricValue('avg_response_time', 60);
        $performanceScore = max(0, 100 - ($avgDuration / 20));

        $uptime = $this->model->getUptimeStatus(5) ? 100.0 : 0.0;
        $uptimeScore = $uptime;

        $p95Duration = $this->model->getP95ResponseTime(60);
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
        $where = ['i.status = ?'];
        $params = [$status];
        if ($level) { $where[] = 'i.level = ?'; $params[] = $level; }
        $whereClause = implode(' AND ', $where);

        $total = $this->model->getIssuesCount($whereClause, $params);
        $issues = $this->model->getIssuesPaged($whereClause, $params, $perPage, $offset);

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
}
