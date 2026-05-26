<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * SystemTelemetryModel — سلامت سیستمی، کارایی و امنیت پلتفرم
 */
class SystemTelemetryModel extends Model
{
    protected static string $table = 'performance_logs';

    public function getActiveAlertRules(): array
    {
        return $this->db->table('alert_rules')
            ->where('is_active', '=', 1)
            ->get();
    }

    public function updateRuleLastTriggered(int $ruleId): bool
    {
        return $this->db->table('alert_rules')
            ->where('id', '=', $ruleId)
            ->update(['last_triggered_at' => date('Y-m-d H:i:s')]);
    }

    public function getErrorCount(int $minutes): int
    {
        return (int) $this->db->table('error_logs')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$minutes} minutes")))
            ->count();
    }

    public function getCriticalErrorCount(int $minutes): int
    {
        return (int) $this->db->table('error_logs')
            ->whereIn('level', ['CRITICAL', 'FATAL'])
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$minutes} minutes")))
            ->count();
    }

    public function getSlowRequestCount(int $minutes): int
    {
        $hasIsSlowColumn = (bool) $this->db->query("SHOW COLUMNS FROM performance_logs LIKE 'is_slow'")->fetch();

        if ($hasIsSlowColumn) {
            return (int) $this->db->table('performance_logs')
                ->where('is_slow', '=', 1)
                ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$minutes} minutes")))
                ->count();
        }

        return (int) $this->db->table('performance_logs')
            ->where('metric', '=', 'request_duration_ms')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$minutes} minutes")))
            ->count();
    }

    public function getFailedLoginCount(int $minutes): int
    {
        return (int) $this->db->table('security_logs')
            ->where('event_type', '=', 'login_failed')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-{$minutes} minutes")))
            ->count();
    }

    public function getSystemHealth(): object
    {
        $dbSize = $this->db->fetch(
            "SELECT ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()"
        );

        $recentErrors = $this->db->table('activity_logs')
            ->select('type')
            ->selectRaw('COUNT(*) as count')
            ->where('level', '=', 'error')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-24 hours")))
            ->groupBy('type')
            ->orderBy('count', 'DESC')
            ->limit(5)
            ->get();

        $rateLimitHits = $this->db->table('rate_limits')
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime("-24 hours")))
            ->where('exceeded', '=', 1)
            ->count();

        return (object)[
            'database_size_mb' => (float)($dbSize->size_mb ?? 0),
            'recent_errors' => $recentErrors ?? [],
            'rate_limit_hits' => (int)$rateLimitHits,
        ];
    }
}
