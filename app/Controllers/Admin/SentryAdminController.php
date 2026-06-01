<?php

namespace App\Controllers\Admin;

use App\Models\SentryModel;
use App\Services\Sentry\Analytics\DashboardService;
use App\Services\Sentry\Analytics\TrendAnalyzer;
use App\Services\Sentry\Alerting\AlertRulesEngine;
use App\Services\Sentry\Alerting\EscalationManager;
use App\Services\Sentry\Audit\AdvancedAuditTrail;
use Core\Response;

/**
 * 🎛️ SentryAdminController - کنترلر پنل ادمین Sentry
 */
class SentryAdminController extends BaseAdminController
{
    private DashboardService $dashboard;
    private TrendAnalyzer $trendAnalyzer;
    private AlertRulesEngine $alertRules;
    private EscalationManager $escalation;
    private AdvancedAuditTrail $audit;
    private SentryModel $model;

    public function __construct(
        DashboardService $dashboard,
        TrendAnalyzer $trendAnalyzer,
        AlertRulesEngine $alertRules,
        EscalationManager $escalation,
        AdvancedAuditTrail $audit,
        SentryModel $model
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->dashboard = $dashboard;
        $this->trendAnalyzer = $trendAnalyzer;
        $this->alertRules = $alertRules;
        $this->escalation = $escalation;
        $this->audit = $audit;
        $this->model = $model;
    }

    /**
     * 🏠 Dashboard Overview
     */
    public function index(): void
    {
        $data = [
            'overview' => $this->dashboard->getOverview(),
            'trends' => [
                'errors' => $this->trendAnalyzer->analyzeTrends('errors', 7),
                'performance' => $this->trendAnalyzer->analyzeTrends('performance', 7),
            ],
            'escalation_stats' => $this->escalation->getStatistics(),
        ];

        view('admin/sentry/dashboard', $data);
    }

    /**
     * 🚨 Issues List
     */
    public function issues(): void
    {
        $page = (int)($_GET['page'] ?? 1);
        $status = $_GET['status'] ?? 'unresolved';
        $level = $_GET['level'] ?? null;

        $issues = $this->dashboard->getIssuesList($page, $status, $level);

        view('admin/sentry/issues', [
            'issues' => $issues,
            'status' => $status,
            'level' => $level,
        ]);
    }

    /**
     * 📝 Issue Details
     */
    public function issueDetails(int $id): void
    {
        $issue = $this->dashboard->getIssueDetails($id);

        if (!$issue) {
            Response::notFound();
            return;
        }

        view('admin/sentry/issue-details', [
            'issue' => $issue,
            'events' => $issue->events,
        ]);
    }

    /**
     * 🧾 Failed Jobs / DLQ
     */
    public function failedJobs(): void
    {
        $page = (int)($_GET['page'] ?? 1);
        $queue = $_GET['queue'] ?? null;

        $failedJobs = $this->dashboard->getFailedJobsList($page, 20, $queue);
        $summary = $this->dashboard->getFailedJobsOverview();

        view('admin/sentry/failed-jobs', [
            'failed_jobs' => $failedJobs,
            'summary' => $summary,
            'queue_counts' => $this->model->getFailedJobQueueCounts(20),
        ]);
    }

    /**
     * � Outbox DLQ
     */
    public function outboxDLQ(): void
    {
        $page = (int)($_GET['page'] ?? 1);

        $outbox = $this->dashboard->getOutboxDLQList($page, 20);
        $summary = $this->dashboard->getOutboxSummary();

        view('admin/sentry/outbox-dlq', [
            'outbox' => $outbox,
            'summary' => $summary,
        ]);
    }

    /**
     * �📝 Failed Job Details
     */
    public function failedJobDetails(int $id): void
    {
        $job = $this->dashboard->getFailedJobDetails($id);
        if (!$job) {
            Response::notFound();
            return;
        }

        view('admin/sentry/failed-job-details', [
            'job' => $job,
        ]);
    }

    /**
     * 🔁 Retry Failed Job
     */
    public function retryFailedJob(int $id): void
    {
        if ($this->dashboard->retryFailedJob($id)) {
            Response::json(['success' => true]);
            return;
        }

        Response::json(['success' => false, 'error' => 'Unable to retry failed job']);
    }

    /**
     * 🗑️ Forget Failed Job
     */
    public function forgetFailedJob(int $id): void
    {
        if ($this->dashboard->forgetFailedJob($id)) {
            Response::json(['success' => true]);
            return;
        }

        Response::json(['success' => false, 'error' => 'Unable to delete failed job']);
    }

    /**
     * 🚀 Performance Monitor
     */
    public function performance(): void
    {
        $period = $_GET['period'] ?? '24h';
        
        $data = [
            'stats' => $this->dashboard->getPerformanceStatistics(),
            'slowest_endpoints' => $this->dashboard->getTopSlowestEndpoints(20),
            'time_series' => $this->dashboard->getTimeSeriesData('performance', $period),
            'degradation' => $this->trendAnalyzer->getPerformanceDegradation(),
        ];

        view('admin/sentry/performance', $data);
    }

    /**
     * 📊 Analytics
     */
    public function analytics(): void
    {
        $metric = $_GET['metric'] ?? 'errors';
        $days = (int)($_GET['days'] ?? 7);

        $data = [
            'trends' => $this->trendAnalyzer->analyzeTrends($metric, $days),
            'hotspots' => $this->trendAnalyzer->getErrorHotspots(),
            'error_sources' => $this->dashboard->getTopErrorSources(15),
            'time_series' => $this->dashboard->getTimeSeriesData($metric, "{$days}d", '1h'),
        ];

        view('admin/sentry/analytics', $data);
    }

    /**
     * 🔔 Alerts Management
     */
    public function alerts(): void
    {
        $activeAlerts = $this->alertRules->getActiveAlerts();
        $rules = $this->alertRules->getAlertRules();

        view('admin/sentry/alerts', [
            'active_alerts' => $activeAlerts,
            'rules' => $rules,
        ]);
    }

    /**
     * ✅ Acknowledge Alert
     */
    public function acknowledgeAlert(): void
    {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        $note = $_POST['note'] ?? null;
        $userId = $this->session->get('user_id');

        if ($this->escalation->acknowledgeAlert($alertId, $userId, $note)) {
            Response::json(['success' => true]);
        } else {
            Response::json(['success' => false, 'error' => 'Failed to acknowledge']);
        }
    }

    /**
     * 📋 Audit Trail
     */
    public function auditTrail(): void
    {
        $filters = [
            'user_id' => $_GET['user_id'] ?? null,
            'event' => $_GET['event'] ?? null,
            'category' => $_GET['category'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'page' => (int)($_GET['page'] ?? 1),
            'per_page' => 50,
        ];

        $results = $this->audit->search($filters);

        view('admin/sentry/audit-trail', [
            'results' => $results,
            'filters' => $filters,
        ]);
    }

    /**
     * 📄 Generate Compliance Report
     */
    public function generateReport(): void
    {
        $startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_POST['end_date'] ?? date('Y-m-d');
        $type = $_POST['type'] ?? 'full';

        $report = $this->audit->generateComplianceReport($startDate, $endDate, $type);

        Response::json($report);
    }

    /**
     * 💾 Export Audit
     */
    public function exportAudit(): void
    {
        $filters = [
            'user_id' => $_GET['user_id'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
        ];

        $filename = 'audit_export_' . date('Y-m-d_H-i-s') . '.csv';
        $path = $this->audit->exportToCSV($filters, $filename);

        // دانلود فایل
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($path);
        exit;
    }

    /**
     * 🔧 Resolve Issue
     */
    public function resolveIssue(): void
    {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $note = $_POST['note'] ?? '';
        $userId = $this->session->get('user_id');

        if ($this->dashboard->resolveIssue($issueId, $userId, $note)) {
            Response::json(['success' => true]);
        } else {
            Response::json(['success' => false, 'error' => 'Failed to resolve issue']);
        }
    }

    /**
     * 🔕 Mute Issue
     */
    public function muteIssue(): void
    {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $duration = $_POST['duration'] ?? '7d';

        if ($this->dashboard->muteIssue($issueId, $duration)) {
            Response::json(['success' => true]);
        } else {
            Response::json(['success' => false, 'error' => 'Failed to mute issue']);
        }
    }

    /**
     * 📊 Get Chart Data (API)
     */
    public function getChartData(): void
    {
        $metric = $_GET['metric'] ?? 'errors';
        $period = $_GET['period'] ?? '24h';
        $interval = $_GET['interval'] ?? '1h';

        $data = $this->dashboard->getTimeSeriesData($metric, $period, $interval);

        Response::json($data);
    }

    /**
     * 💚 Health Check (API)
     */
    public function healthCheck(): void
    {
        $health = $this->dashboard->calculateHealthScore();
        Response::json($health);
    }

    // ==========================================
    // Helper Methods
    // ==========================================
}
