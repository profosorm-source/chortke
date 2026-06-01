<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Contracts\LoggerInterface;
/**
 * FraudDashboardService
 * 
 * سرویس داشبورد Real-time برای مانیتورینگ تهدیدات
 */
class FraudDashboardService
{
    private VelocityAndScoreModel $model;
    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model
    )
    {        $this->logger = $logger;

                $this->model = $model;
    }

    /**
     * دریافت آمار کلی (Overview)
     */
    public function getOverview(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $counts = $this->model->getOverviewCounts($since);
        
        $detectionRate = ($counts->total_sessions > 0)
            ? round(($counts->total_frauds / $counts->total_sessions) * 100, 2)
            : 0;
        
        return [
            'total_frauds' => (int)($counts->total_frauds ?? 0),
            'active_alerts' => (int)($counts->active_alerts ?? 0),
            'blocked_users' => (int)($counts->blocked_users ?? 0),
            'total_sessions' => (int)($counts->total_sessions ?? 0),
            'detection_rate_percent' => $detectionRate,
            'period_hours' => $hours
        ];
    }

    /**
     * دریافت Alert های اخیر
     */
    public function getRecentAlerts(int $limit = 50, ?string $severity = null): array
    {
        $alerts = $this->model->getRecentAlerts($limit, $severity);
        
        return array_map(function($alert) {
            return [
                'id' => $alert->id,
                'type' => $alert->alert_type,
                'severity' => $alert->severity,
                'user_id' => $alert->user_id,
                'title' => $alert->title,
                'description' => $alert->description,
                'details' => json_decode($alert->details ?? '{}', true),
                'status' => $alert->status,
                'created_at' => $alert->created_at
            ];
        }, $alerts);
    }

    /**
     * توزیع Fraud بر اساس نوع
     */
    public function getFraudTypeDistribution(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $results = $this->model->getFraudTypeDistribution($since);
        
        return array_map(function($row) {
            return [
                'type' => $row->fraud_type,
                'count' => (int)$row->count,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2)
            ];
        }, $results);
    }

    /**
     * روند Fraud در طول زمان (Hourly)
     */
    public function getHourlyTrend(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $results = $this->model->getHourlyTrend($since);
        
        return array_map(function($row) {
            return [
                'hour' => $row->hour,
                'count' => (int)$row->count,
                'avg_risk' => round((float)$row->avg_risk, 2)
            ];
        }, $results);
    }

    /**
     * نقشه جغرافیایی تهدیدات
     */
    public function getGeographicThreats(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $results = $this->model->getGeographicThreats($since);
        
        return array_map(function($row) {
            return [
                'country_code' => $row->country,
                'country_name' => $row->country_name,
                'fraud_count' => (int)$row->fraud_count,
                'affected_users' => (int)$row->affected_users,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2)
            ];
        }, $results);
    }

    /**
     * Top کاربران مشکوک
     */
    public function getTopSuspiciousUsers(int $limit = 20): array
    {
        $results = $this->model->getTopSuspiciousUsers($limit);
        
        return array_map(function($row) {
            return [
                'user_id' => (int)$row->id,
                'username' => $row->username,
                'email' => $row->email,
                'fraud_count' => (int)$row->fraud_count,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2),
                'last_fraud_at' => $row->last_fraud_at,
                'fraud_score' => (int)$row->fraud_score,
                'is_blacklisted' => (bool)$row->is_blacklisted
            ];
        }, $results);
    }

    /**
     * IP های مشکوک
     */
    public function getTopSuspiciousIPs(int $limit = 20): array
    {
        $results = $this->model->getTopSuspiciousIPs($limit);
        
        return array_map(function($row) {
            return [
                'ip_address' => $row->ip_address,
                'user_count' => (int)$row->user_count,
                'fraud_count' => (int)$row->fraud_count,
                'avg_risk_score' => round((float)$row->avg_risk_score, 2),
                'last_seen' => $row->last_seen
            ];
        }, $results);
    }

    /**
     * Rate Limit Violations
     */
    public function getRateLimitViolations(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $results = $this->model->getRateLimitViolations($since);
        
        return array_map(function($row) {
            return [
                'action' => $row->action,
                'violation_count' => (int)$row->count,
                'unique_identifiers' => (int)$row->unique_identifiers
            ];
        }, $results);
    }

    /**
     * Device Intelligence Stats
     */
    public function getDeviceStats(int $hours = 24): array
    {
        $since = date('Y-m-d H:i:s', time() - ($hours * 3600));
        $result = $this->model->getDeviceStats($since);
        
        return [
            'total_devices' => (int)($result->total ?? 0),
            'emulator_count' => (int)($result->emulator_count ?? 0),
            'vm_count' => (int)($result->vm_count ?? 0),
            'automation_count' => (int)($result->automation_count ?? 0),
            'avg_risk_score' => round((float)($result->avg_risk_score ?? 0), 2),
            'emulator_percentage' => ($result && $result->total > 0)
                ? round(($result->emulator_count / $result->total) * 100, 2)
                : 0
        ];
    }

    /**
     * ایجاد Alert جدید
     */
    public function createAlert(
        string $alertType,
        string $severity,
        string $title,
        ?int $userId = null,
        ?string $description = null,
        ?array $details = null
    ): int {
        $alertId = $this->model->createAlert([
            'alert_type' => $alertType,
            'severity' => $severity,
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'details' => $details
        ]);
        
        $this->logger->warning('fraud.alert.created', [
            'alert_id' => $alertId,
            'type' => $alertType,
            'severity' => $severity,
            'user_id' => $userId
        ]);
        
        return $alertId;
    }

    /**
     * به‌روزرسانی وضعیت Alert
     */
    public function updateAlertStatus(
        int $alertId,
        string $status,
        ?int $assignedTo = null
    ): bool {
        return $this->model->updateAlertStatus($alertId, $status, $assignedTo);
    }

    /**
     * Performance Metrics
     */
    public function getPerformanceMetrics(): array
    {
        $metrics = $this->model->getPerformanceMetrics();
        
        $fpRate = ($metrics->total_alerts > 0)
            ? round(($metrics->fp_count / $metrics->total_alerts) * 100, 2)
            : 0;
        
        return [
            'avg_detection_time_seconds' => round((float)($metrics->avg_detection_time ?? 0), 2),
            'false_positive_rate_percent' => $fpRate,
            'avg_resolution_time_minutes' => round((float)($metrics->avg_resolution_time ?? 0), 2)
        ];
    }

    /**
     * داده برای نمودار Real-time (آخرین 60 دقیقه)
     */
    public function getRealTimeChartData(): array
    {
        $results = $this->model->getRealTimeData();
        
        $labels = [];
        $data = [];
        
        foreach ($results as $row) {
            $labels[] = $row->minute;
            $data[] = (int)$row->count;
        }
        
        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    /**
     * خلاصه کامل داشبورد
     */
    public function getCompleteDashboard(): array
    {
        return [
            'overview' => $this->getOverview(24),
            'recent_alerts' => $this->getRecentAlerts(10),
            'fraud_type_distribution' => $this->getFraudTypeDistribution(24),
            'hourly_trend' => $this->getHourlyTrend(24),
            'geographic_threats' => $this->getGeographicThreats(24),
            'top_suspicious_users' => $this->getTopSuspiciousUsers(10),
            'top_suspicious_ips' => $this->getTopSuspiciousIPs(10),
            'rate_limit_violations' => $this->getRateLimitViolations(24),
            'device_stats' => $this->getDeviceStats(24),
            'performance_metrics' => $this->getPerformanceMetrics(),
            'realtime_chart' => $this->getRealTimeChartData(),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
}

