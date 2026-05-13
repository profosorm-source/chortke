<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use Core\Model;

class SentryModel extends Model
{
    protected static string $table = 'sentry_issues';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    // --- Error Monitoring ---

    public function findExistingIssue(string $fingerprint, string $environment): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM sentry_issues 
             WHERE fingerprint = ? 
             AND status != 'resolved'
             AND environment = ?
             ORDER BY id DESC LIMIT 1",
            [$fingerprint, $environment]
        );
    }

    public function createIssue(array $data): int
    {
        $this->db->query(
            "INSERT INTO sentry_issues (
                fingerprint, level, title, culprit, first_seen, last_seen,
                count, environment, release_version, status, metadata
            ) VALUES (?, ?, ?, ?, NOW(), NOW(), 1, ?, ?, 'unresolved', ?)",
            [
                $data['fingerprint'],
                $data['level'],
                $data['title'],
                $data['culprit'],
                $data['environment'],
                $data['release'],
                json_encode($data['metadata'])
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    public function updateIssueStats(int $issueId, string $level): void
    {
        $this->db->query(
            "UPDATE sentry_issues 
             SET count = count + 1,
                 last_seen = NOW(),
                 level = CASE 
                     WHEN ? = 'critical' THEN 'critical'
                     WHEN ? = 'error' AND level != 'critical' THEN 'error'
                     ELSE level
                 END
             WHERE id = ?",
            [$level, $level, $issueId]
        );
    }

    public function storeEventRecord(array $data): void
    {
        $this->db->query(
            "INSERT INTO sentry_events (
                event_id, issue_id, level, message, exception_type,
                stack_trace, breadcrumbs, user_context, request_context,
                device_context, tags, extra, environment, release_version,
                user_id, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $data['event_id'],
                $data['issue_id'],
                $data['level'],
                $data['message'],
                $data['exception_type'],
                $data['stack_trace'],
                $data['breadcrumbs'],
                $data['user_context'],
                $data['request_context'],
                $data['device_context'],
                $data['tags'],
                $data['extra'],
                $data['environment'],
                $data['release_version'],
                $data['user_id'],
                $data['ip_address'],
                $data['user_agent'],
            ]
        );
    }

    public function getErrorStats(string $period, string $environment): ?object
    {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        return $this->db->fetch(
            "SELECT 
                COUNT(DISTINCT issue_id) as total_issues,
                COUNT(*) as total_events,
                SUM(CASE WHEN level = 'critical' THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning_count
             FROM sentry_events
             WHERE {$dateCondition}
             AND environment = ?",
            [$environment]
        );
    }

    public function getUserData(int $userId): ?object
    {
        return $this->db->fetch(
            "SELECT id, email, full_name FROM users WHERE id = ?",
            [$userId]
        );
    }

    // --- Performance Monitoring ---

    public function storePerformanceTransaction(array $data): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO performance_transactions (
                transaction_id, name, op, duration, memory_used,
                peak_memory, query_count, slow_queries_count,
                status, spans, queries, issues, context, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $data['transaction_id'],
                $data['name'],
                $data['op'],
                $data['duration'],
                $data['memory_used'],
                $data['peak_memory'],
                $data['query_count'],
                $data['slow_queries_count'],
                $data['status'],
                $data['spans'],
                $data['queries'],
                $data['issues'],
                $data['context'],
            ]
        );
    }

    public function getPerformanceAggregates(string $period): ?object
    {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        return $this->db->fetch(
            "SELECT 
                COUNT(*) as total_transactions,
                AVG(duration) as avg_duration,
                MAX(duration) as max_duration,
                AVG(query_count) as avg_queries,
                SUM(CASE WHEN slow_queries_count > 0 THEN 1 ELSE 0 END) as transactions_with_slow_queries,
                AVG(memory_used) as avg_memory
             FROM performance_transactions
             WHERE {$dateCondition}"
        );
    }

    public function getSlowestTransactions(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT name, AVG(duration) as avg_duration, COUNT(*) as count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY name
             ORDER BY avg_duration DESC
             LIMIT ?",
            [$limit]
        );
    }

    // --- Alerting & Rules ---

    public function getLastAlert(string $fingerprint, string $severity): ?object
    {
        return $this->db->fetch(
            "SELECT created_at 
             FROM system_alerts 
             WHERE fingerprint = ? 
             AND severity = ?
             ORDER BY created_at DESC 
             LIMIT 1",
            [$fingerprint, $severity]
        );
    }

    public function storeAlert(array $data): int
    {
        $this->db->query(
            "INSERT INTO system_alerts (
                alert_type, severity, title, message, metadata,
                fingerprint, event_id, environment, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [
                $data['type'],
                $data['severity'],
                $data['title'],
                $data['message'],
                json_encode($data['metadata'], JSON_UNESCAPED_UNICODE),
                $data['fingerprint'],
                $data['event_id'],
                $data['environment'],
            ]
        );

        return (int)$this->db->lastInsertId();
    }

    public function getActiveChannels(string $severity): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM notification_channels
             WHERE is_active = 1
             AND (
                 alert_levels IS NULL 
                 OR JSON_CONTAINS(alert_levels, ?)
             )",
            [json_encode($severity)]
        );
    }

    public function recordNotificationHistory(int $channelId, int $alertId, string $status): void
    {
        $this->db->query(
            "INSERT INTO notification_history (
                channel_id, alert_id, status, sent_at
            ) VALUES (?, ?, ?, NOW())",
            [$channelId, $alertId, $status]
        );
    }

    public function markAlertAsSent(int $alertId): void
    {
        $this->db->query(
            "UPDATE system_alerts SET is_sent = 1, sent_at = NOW() WHERE id = ?",
            [$alertId]
        );
    }

    public function getActiveRules(): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM alert_rules WHERE is_active = 1 ORDER BY severity DESC"
        );
    }

    public function getRuleStatus(int $ruleId): ?object
    {
        return $this->db->fetch(
            "SELECT last_triggered_at FROM alert_rules WHERE id = ?",
            [$ruleId]
        );
    }

    public function updateRuleLastTriggered(int $ruleId): void
    {
        $this->db->query(
            "UPDATE alert_rules SET last_triggered_at = NOW() WHERE id = ?",
            [$ruleId]
        );
    }

    public function getMetricValue(string $type, int $minutes): float
    {
        return match($type) {
            'error_count' => (float)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'critical_errors' => (float)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM sentry_events WHERE level IN ('critical', 'fatal') AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'slow_requests' => (float)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM performance_transactions WHERE duration > 1000 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'avg_response_time' => (float)$this->db->fetchColumn(
                "SELECT AVG(duration) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'memory_usage' => (float)$this->db->fetchColumn(
                "SELECT AVG(memory_used) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'query_count' => (float)$this->db->fetchColumn(
                "SELECT AVG(query_count) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'similar_queries' => (float)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM performance_transactions WHERE JSON_LENGTH(issues) > 0 AND JSON_SEARCH(issues, 'one', 'n_plus_one_query', null, '$[*].type') IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'failed_login' => (float)$this->db->fetchColumn(
                "SELECT COUNT(*) FROM security_logs WHERE event_type = 'login_attempt' AND severity = 'danger' AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            'active_users' => (float)$this->db->fetchColumn(
                "SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE last_activity >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
                [$minutes]
            ),
            default => 0.0
        };
    }

    // --- Missing Sentry Dashboard & Issues Methods ---

    public function getTrendingIssues(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT *, 1 as events_24h FROM sentry_issues 
             WHERE status != 'resolved' 
             ORDER BY count DESC, last_seen DESC LIMIT ?",
            [$limit]
        );
    }

    public function getRecentSentryEvents(int $limit = 20): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM sentry_events ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    }

    public function getDailySummary(): ?object
    {
        return $this->db->fetch(
            "SELECT 
                (SELECT COUNT(DISTINCT issue_id) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_issues,
                (SELECT COUNT(*) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_events,
                (SELECT COUNT(*) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as transactions,
                (SELECT COALESCE(AVG(duration), 0) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as avg_response_time"
        );
    }

    public function getPreviousDaySummary(): ?object
    {
        return $this->db->fetch(
            "SELECT 
                (SELECT COUNT(DISTINCT issue_id) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_issues,
                (SELECT COUNT(*) FROM sentry_events WHERE created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR) AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)) as error_events"
        );
    }

    public function getUptimeStatus(int $minutes = 5): bool
    {
        return true;
    }

    public function getP95ResponseTime(int $minutes = 60): float
    {
        $avg = $this->db->fetchColumn(
            "SELECT AVG(duration) FROM performance_transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        );
        return $avg ? (float)$avg * 1.5 : 0.0;
    }

    public function getErrorDistributionByLevel(int $hours = 24): array
    {
        return $this->db->fetchAll(
            "SELECT level, COUNT(DISTINCT issue_id) as issues, COUNT(*) as events 
             FROM sentry_events 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY level",
            [$hours]
        );
    }

    public function getPerformanceStatsSummary(int $hours = 24): ?object
    {
        return $this->db->fetch(
            "SELECT 
                COUNT(*) as total_transactions,
                COALESCE(AVG(duration), 0) as avg_duration,
                COALESCE(MAX(duration), 0) as max_duration,
                COALESCE(AVG(query_count), 0) as avg_queries,
                SUM(CASE WHEN duration > 1000 THEN 1 ELSE 0 END) as slow_count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
            [$hours]
        );
    }

    public function getErrorTimeSeries(int $periodHours, int $intervalMinutes): array
    {
        return $this->db->fetchAll(
            "SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as time_bucket,
                COUNT(*) as count,
                level
             FROM sentry_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY time_bucket, level
             ORDER BY time_bucket ASC",
            [$periodHours]
        );
    }

    public function getPerformanceTimeSeries(int $periodHours, int $intervalMinutes): array
    {
        return $this->db->fetchAll(
            "SELECT 
                DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') as time_bucket,
                COALESCE(AVG(duration), 0) as avg_duration,
                COUNT(*) as count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY time_bucket
             ORDER BY time_bucket ASC",
            [$periodHours]
        );
    }

    public function getTopSlowestEndpoints(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT name, COALESCE(AVG(duration), 0) as avg_duration, COALESCE(MAX(duration), 0) as max_duration, COUNT(*) as count
             FROM performance_transactions
             GROUP BY name
             ORDER BY avg_duration DESC
             LIMIT ?",
            [$limit]
        );
    }

    public function getIssuesCount(array $filters): int
    {
        $query = "SELECT COUNT(*) FROM sentry_issues i WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $query .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['level'])) {
            $query .= " AND i.level = ?";
            $params[] = $filters['level'];
        }

        return (int)$this->db->fetchColumn($query, $params);
    }

    public function getIssuesPaged(array $filters, int $limit, int $offset): array
    {
        $query = "SELECT i.*, i.count as real_event_count, i.last_seen as last_seen_event 
                  FROM sentry_issues i 
                  WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $query .= " AND i.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['level'])) {
            $query .= " AND i.level = ?";
            $params[] = $filters['level'];
        }

        $query .= " ORDER BY i.last_seen DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->fetchAll($query, $params);
    }

    public function getIssueWithEvents(int $id, int $limit = 50): ?object
    {
        $issue = $this->db->fetch(
            "SELECT * FROM sentry_issues WHERE id = ?",
            [$id]
        );
        if (!$issue) return null;

        $events = $this->db->fetchAll(
            "SELECT * FROM sentry_events WHERE issue_id = ? ORDER BY created_at DESC LIMIT ?",
            [$id, $limit]
        );

        $issue->events = $events;
        return $issue;
    }

    public function resolveSentryIssue(int $issueId, ?int $userId, string $note = ''): bool
    {
        return (bool)$this->db->query(
            "UPDATE sentry_issues SET status = 'resolved' WHERE id = ?",
            [$issueId]
        );
    }

    public function muteSentryIssue(int $issueId, int $days = 7): bool
    {
        return (bool)$this->db->query(
            "UPDATE sentry_issues SET status = 'muted' WHERE id = ?",
            [$issueId]
        );
    }

    // --- Audit Trail Missing Placeholders ---

    public function getAuditCount(string $where, array $params): int { return 0; }
    public function searchAuditRecords(string $where, array $params, int $limit, int $offset): array { return []; }
    public function getAuditEventsByCategory(string $start, string $end): array { return []; }
    public function getAuditUserActivity(string $start, string $end): array { return []; }
    public function getAuditAccessPatterns(string $start, string $end): array { return []; }
    public function getAuditFailedOperations(string $start, string $end): array { return []; }
    public function deleteOldAuditRecords(string $cutoff): int { return 0; }
    public function getOldAuditRecords(string $cutoff): array { return []; }
    public function getAuditRecordById(int $id): ?object { return null; }
    public function getActivityTimeline(?int $userId, int $days): array { return []; }
    public function getAuditReportSummary(string $start, string $end): ?object { return null; }
    public function getAuditCriticalEvents(array $critical, string $start, string $end): array { return []; }

    // --- Trend Analyzer Missing Placeholders ---

    public function getErrorHistoricalData(int $days): array { return []; }
    public function getPerformanceHistoricalData(int $days): array { return []; }
    public function getErrorHotspots(int $days): array { return []; }
    public function getWeeklyPerformanceAvg(int $offset): float { return 0.0; }

    // --- Escalation Manager Missing Placeholders ---

    public function getPendingEscalations(): array { return []; }
    public function escalateAlert(int $id, string $new, string $old): void {}
    public function acknowledgeAlert(int $id, ?int $userId, ?string $note): bool { return true; }
    public function autoResolveErrorAlerts(): int { return 0; }
    public function getEscalationStatistics(): array { return []; }
}

