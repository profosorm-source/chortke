<?php

namespace App\Models;
use Core\Model;

class AdvancedAnalytics extends Model {

    public function getTrendData(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30,
        array $conditions = [],
        array $groupByColumns = []
    ): array {
        $where = ["{$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
        $params = [$days];

        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $where[] = "{$column} IS NULL";
            } else {
                $where[] = "{$column} = ?";
                $params[] = $value;
            }
        }

        $groupBy = empty($groupByColumns)
            ? "DATE({$dateColumn})"
            : "DATE({$dateColumn}), " . implode(', ', $groupByColumns);

        $select = "DATE({$dateColumn}) as date, COUNT(*) as total";
        if (!empty($groupByColumns)) {
            $select .= ', ' . implode(', ', $groupByColumns);
        }

        $sql = "SELECT {$select}
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                GROUP BY {$groupBy}
                ORDER BY date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getDistributionData(string $table, string $column, array $conditions = [], int $limit = 10): array
    {
        $where = ['1=1'];
        $params = [];

        foreach ($conditions as $col => $value) {
            if ($value === null) {
                $where[] = "{$col} IS NULL";
            } else {
                $where[] = "{$col} = ?";
                $params[] = $value;
            }
        }

        $sql = "SELECT {$column} AS label, COUNT(*) AS value,
                    ROUND(COUNT(*) * 100.0 / (
                        SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where) . "), 2) as percentage
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                GROUP BY {$column}
                ORDER BY value DESC
                LIMIT ?";

        $params[] = $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getRankingData(string $sql, array $params = [], int $limit = 10): array
    {
        $sql .= " LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getDescriptiveStatsData(string $table, string $column, array $conditions = []): array
    {
        $where = ['1=1'];
        $params = [];

        foreach ($conditions as $col => $value) {
            $where[] = "{$col} = ?";
            $params[] = $value;
        }

        $sql = "SELECT
                    COUNT(*) as count,
                    AVG({$column}) as mean,
                    MIN({$column}) as min,
                    MAX({$column}) as max,
                    STDDEV({$column}) as stddev
                FROM {$table}
                WHERE " . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            return [
                'count' => 0,
                'mean' => 0,
                'min' => 0,
                'max' => 0,
                'stddev' => 0,
            ];
        }

        return [
            'count' => (int)$result['count'],
            'mean' => round((float)$result['mean'], 2),
            'min' => (float)$result['min'],
            'max' => (float)$result['max'],
            'stddev' => round((float)($result['stddev'] ?? 0), 2),
        ];
    }

    public function getCohortAnalysisData(
        string $table,
        string $userIdColumn = 'user_id',
        string $dateColumn = 'created_at',
        int $months = 6
    ): array {
        $sql = "SELECT
                    DATE_FORMAT({$dateColumn}, '%Y-%m') as cohort_month,
                    COUNT(DISTINCT {$userIdColumn}) as users_count
                FROM {$table}
                WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                GROUP BY cohort_month
                ORDER BY cohort_month ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$months]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getRetentionRateData(
        string $table,
        string $userIdColumn = 'user_id',
        string $dateColumn = 'created_at'
    ): float {
        $sql = "SELECT
                    COUNT(DISTINCT CASE
                        WHEN activity_count > 1 THEN {$userIdColumn}
                    END) * 100.0 / COUNT(DISTINCT {$userIdColumn}) as retention_rate
                FROM (
                    SELECT
                        {$userIdColumn},
                        COUNT(*) as activity_count
                    FROM {$table}
                    GROUP BY {$userIdColumn}
                ) as user_activities";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return round((float)($result['retention_rate'] ?? 0), 2);
    }

    public function getPeakHoursData(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30
    ): array {
        $sql = "SELECT
                    HOUR({$dateColumn}) as hour,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (
                        SELECT COUNT(*) FROM {$table}
                        WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)
                    ), 2) as percentage
                FROM {$table}
                WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY HOUR({$dateColumn})
                ORDER BY count DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$days, $days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getPeriodStatsData(
        string $table,
        string $dateColumn,
        int $offsetDays,
        int $periodDays,
        array $conditions = []
    ): array {
        $where = [
            "{$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            "{$dateColumn} < DATE_SUB(NOW(), INTERVAL ? DAY)",
        ];

        $params = [$offsetDays + $periodDays, $offsetDays];

        foreach ($conditions as $column => $value) {
            $where[] = "{$column} = ?";
            $params[] = $value;
        }

        $sql = "SELECT COUNT(*) as total FROM {$table} WHERE " . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total' => (int)($result['total'] ?? 0),
            'period_days' => $periodDays,
        ];
    }
}
