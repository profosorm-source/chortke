<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;

class SentryModel
{
    public function __construct(private Database $db) {}

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

        return (int)$this->db->getConnection()->lastInsertId();
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

    public function getErrorStats(string $dateCondition, string $environment): ?object
    {
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

    public function getPerformanceAggregates(string $dateCondition): ?object
    {
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

        return (int)$this->db->getConnection()->lastInsertId();
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
}
