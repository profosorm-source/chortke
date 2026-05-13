<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * FraudAnalyticsModel - Fraud Dashboards, Overviews & Logging Data Access Layer
 */
class FraudAnalyticsModel extends Model
{
    protected static string $table = 'fraud_logs';

    public function getOverviewCounts(string $since): object
    {
        $sql = "SELECT 
                    (SELECT COUNT(*) FROM fraud_logs WHERE created_at >= ?) as total_frauds,
                    (SELECT COUNT(*) FROM fraud_alerts WHERE status = 'pending' AND created_at >= ?) as active_alerts,
                    (SELECT COUNT(DISTINCT user_id) FROM fraud_logs WHERE action_taken = 'block' AND created_at >= ?) as blocked_users,
                    (SELECT COUNT(*) FROM user_sessions WHERE created_at >= ?) as total_sessions";
        
        return $this->db->fetch($sql, [$since, $since, $since, $since]);
    }

    public function getRecentAlerts(int $limit, ?string $severity = null): array
    {
        $sql = "SELECT * FROM fraud_alerts WHERE 1=1";
        $params = [];
        
        if ($severity) {
            $sql .= " AND severity = ?";
            $params[] = $severity;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        return $this->db->fetchAll($sql, $params);
    }

    public function getFraudTypeDistribution(string $since): array
    {
        $sql = "SELECT 
                    fraud_type,
                    COUNT(*) as count,
                    AVG(risk_score) as avg_risk_score
                FROM fraud_logs 
                WHERE created_at >= ?
                GROUP BY fraud_type
                ORDER BY count DESC";
        
        return $this->db->fetchAll($sql, [$since]);
    }

    public function getHourlyTrend(string $since): array
    {
        return $this->db->table('fraud_logs as fl')
            ->select('fl.*')
            ->selectRaw("DATE_FORMAT(fl.created_at, '%Y-%m-%d %H:00:00') as hour")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('AVG(fl.risk_score) as avg_risk')
            ->where('fl.created_at', '>=', $since)
            ->groupBy('hour')
            ->orderBy('hour', 'ASC')
            ->get() ?? [];
    }

    public function getGeographicThreats(string $since): array
    {
        $sql = "SELECT 
                    s.country,
                    s.country_name,
                    COUNT(DISTINCT f.id) as fraud_count,
                    COUNT(DISTINCT f.user_id) as affected_users,
                    AVG(f.risk_score) as avg_risk_score
                FROM fraud_logs f
                JOIN user_sessions s ON f.session_id = s.session_id
                WHERE f.created_at >= ?
                AND s.country IS NOT NULL
                GROUP BY s.country, s.country_name
                ORDER BY fraud_count DESC";
        
        return $this->db->fetchAll($sql, [$since]);
    }

    public function logFraudEvent(array $data): bool
    {
        return (bool)$this->db->query(
            "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, ip_address, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$data['user_id'], $data['type'], $data['score'], json_encode($data['details'], JSON_UNESCAPED_UNICODE), $data['ip'] ?? null]
        );
    }
}
