<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

class AnalyticsReport extends Model
{
    protected static string $table = '';

    public function fetchTrendData(
        string $table,
        string $dateColumn,
        int $days,
        array $conditions,
        array $groupByColumns
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

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function fetchDistributionData(string $table, string $column, array $conditions, int $limit): array
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

        $sql = "SELECT 
                    {$column} as label,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where) . "), 2) as percentage
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                GROUP BY {$column}
                ORDER BY count DESC
                LIMIT ?";

        $params[] = $limit;
        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function fetchRankingData(string $sql, array $params, int $limit): array
    {
        $sql .= ' LIMIT ?';
        $params[] = $limit;
        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function fetchDescriptiveStats(string $table, string $column, array $conditions): array
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

        $stmt = $this->db->query($sql, $params);
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;

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

    public function fetchCohortAnalysis(string $table, string $userIdColumn, string $dateColumn, int $months): array
    {
        $sql = "SELECT 
                    DATE_FORMAT({$dateColumn}, '%Y-%m') as cohort_month,
                    COUNT(DISTINCT {$userIdColumn}) as users_count
                FROM {$table}
                WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL ? MONTH)
                GROUP BY cohort_month
                ORDER BY cohort_month ASC";

        $stmt = $this->db->query($sql, [$months]);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function fetchRetentionRate(string $table, string $userIdColumn, string $dateColumn): float
    {
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

        $stmt = $this->db->query($sql);
        $result = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
        return round((float)($result['retention_rate'] ?? 0), 2);
    }

    public function fetchPeakHours(string $table, string $dateColumn, int $days): array
    {
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

        $stmt = $this->db->query($sql, [$days, $days]);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }
}
