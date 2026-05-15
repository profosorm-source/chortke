<?php

declare(strict_types=1);

namespace App\Models;

use Core\Model;

/**
 * AdvancedAnalytics Model - Fully Hardened against SQL Injection
 */
class AdvancedAnalytics extends Model
{
    protected static string $table = 'analytics';

    private array $allowedTables = [
        'users', 'transactions', 'withdrawals', 'deposits', 
        'activity_logs', 'social_ads', 'payments', 'tickets', 
        'manual_deposits', 'crypto_deposits', 'advertisements', 'task_executions',
        'social_ratings', 'custom_tasks', 'custom_task_submissions', 'rate_limits'
    ];

    private array $allowedColumns = [
        'id', 'created_at', 'updated_at', 'completed_at', 'verified_at',
        'status', 'type', 'platform', 'currency', 'user_id', 'amount',
        'executor_id', 'adS_id', 'reward_amount', 'rating', 'value', 'price_usdt',
        'level_id', 'stars', 'rated_id', 'decision', 'task_score'
    ];

    private function validateTableName(string $table): void
    {
        $table = \strtolower(\trim($table));
        if (!\in_array($table, $this->allowedTables, true)) {
            throw new \InvalidArgumentException("Invalid table name: {$table}");
        }
    }

    private function validateColumnName(string $column): void
    {
        $column = \strtolower(\trim($column));
        if (!\in_array($column, $this->allowedColumns, true)) {
            throw new \InvalidArgumentException("Invalid column name: {$column}");
        }
    }

    public function getTrendData(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30,
        array $conditions = [],
        array $groupByColumns = []
    ): array {
        $this->validateTableName($table);
        $this->validateColumnName($dateColumn);

        $where = ["`{$dateColumn}` >= DATE_SUB(NOW(), INTERVAL ? DAY)"];
        $params = [$days];

        foreach ($conditions as $column => $value) {
            $this->validateColumnName($column);
            if ($value === null) {
                $where[] = "`{$column}` IS NULL";
            } else {
                $where[] = "`{$column}` = ?";
                $params[] = $value;
            }
        }

        $groupBy = empty($groupByColumns)
            ? "DATE(`{$dateColumn}`)"
            : "DATE(`{$dateColumn}`), " . \implode(', ', \array_map(fn($c) => "`{$c}`", $groupByColumns));

        foreach ($groupByColumns as $col) {
            $this->validateColumnName($col);
        }

        $select = "DATE(`{$dateColumn}`) as date, COUNT(*) as total";
        if (!empty($groupByColumns)) {
            $select .= ', ' . \implode(', ', \array_map(fn($c) => "`{$c}`", $groupByColumns));
        }

        $sql = "SELECT {$select}
                FROM `{$table}`
                WHERE " . \implode(' AND ', $where) . "
                GROUP BY {$groupBy}
                ORDER BY date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getDistributionData(string $table, string $column, array $conditions = [], int $limit = 10): array
    {
        $this->validateTableName($table);
        $this->validateColumnName($column);

        $where = ['1=1'];
        $params = [];

        foreach ($conditions as $col => $value) {
            $this->validateColumnName($col);
            if ($value === null) {
                $where[] = "`{$col}` IS NULL";
            } else {
                $where[] = "`{$col}` = ?";
                $params[] = $value;
            }
        }

        $sql = "SELECT `{$column}` AS label, COUNT(*) AS value,
                    ROUND(COUNT(*) * 100.0 / (
                        SELECT COUNT(*) FROM `{$table}` WHERE " . \implode(' AND ', $where) . "), 2) as percentage
                FROM `{$table}`
                WHERE " . \implode(' AND ', $where) . "
                GROUP BY `{$column}`
                ORDER BY value DESC
                LIMIT ?";

        // ✅ M-08: Params must include WHERE conditions twice (subquery + main query)
        $allParams = array_merge($params, $params); // Duplicate params for subquery and main query
        $allParams[] = $limit;
        
        $stmt = $this->db->prepare($sql);
        
        // Bind limit strictly
        foreach ($allParams as $index => $val) {
            if ($index === \count($allParams) - 1) {
                $stmt->bindValue($index + 1, (int)$val, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($index + 1, $val);
            }
        }

        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getRankingData(
        string $table,
        string $metricColumn,
        string $groupByColumn,
        array $conditions = [],
        int $limit = 10
    ): array {
        $this->validateTableName($table);
        $this->validateColumnName($metricColumn);
        $this->validateColumnName($groupByColumn);

        $where = ['1=1'];
        $params = [];

        foreach ($conditions as $column => $value) {
            $this->validateColumnName($column);
            $where[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $sql = "SELECT `{$groupByColumn}`, SUM(`{$metricColumn}`) as total
                FROM `{$table}`
                WHERE " . \implode(' AND ', $where) . "
                GROUP BY `{$groupByColumn}`
                ORDER BY total DESC
                LIMIT ?";

        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $index => $val) {
            if ($index === \count($params) - 1) {
                $stmt->bindValue($index + 1, (int)$val, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($index + 1, $val);
            }
        }
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getDescriptiveStatsData(string $table, string $column, array $conditions = []): array
    {
        $this->validateTableName($table);
        $this->validateColumnName($column);

        $where = ['1=1'];
        $params = [];

        foreach ($conditions as $col => $value) {
            $this->validateColumnName($col);
            $where[] = "`{$col}` = ?";
            $params[] = $value;
        }

        $sql = "SELECT
                    COUNT(*) as count,
                    AVG(`{$column}`) as mean,
                    MIN(`{$column}`) as min,
                    MAX(`{$column}`) as max,
                    STDDEV(`{$column}`) as stddev
                FROM `{$table}`
                WHERE " . \implode(' AND ', $where);

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
        $this->validateTableName($table);
        $this->validateColumnName($userIdColumn);
        $this->validateColumnName($dateColumn);

        return $this->db->table($table)
            ->selectRaw("DATE_FORMAT(`{$dateColumn}`, '%Y-%m') as cohort_month")
            ->selectRaw("COUNT(DISTINCT `{$userIdColumn}`) as users_count")
            ->whereRaw("`{$dateColumn}` >= DATE_SUB(NOW(), INTERVAL ? MONTH)", [$months])
            ->groupBy('cohort_month')
            ->orderBy('cohort_month', 'ASC')
            ->get() ?? [];
    }

    public function getRetentionRateData(
        string $table,
        string $userIdColumn = 'user_id',
        string $dateColumn = 'created_at'
    ): float {
        $this->validateTableName($table);
        $this->validateColumnName($userIdColumn);
        $this->validateColumnName($dateColumn);

        $result = $this->db->fetch(
            "SELECT
                COUNT(DISTINCT CASE
                    WHEN activity_count > 1 THEN `{$userIdColumn}`
                END) * 100.0 / COUNT(DISTINCT `{$userIdColumn}`) as retention_rate
            FROM (
                SELECT
                    `{$userIdColumn}`,
                    COUNT(*) as activity_count
                FROM `{$table}`
                GROUP BY `{$userIdColumn}`
            ) as user_activities"
        );

        return round((float)($result->retention_rate ?? 0), 2);
    }

    public function getPeakHoursData(
        string $table,
        string $dateColumn = 'created_at',
        int $days = 30
    ): array {
        $this->validateTableName($table);
        $this->validateColumnName($dateColumn);

        return $this->db->table($table)
            ->selectRaw("HOUR(`{$dateColumn}`) as hour")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw("ROUND(COUNT(*) * 100.0 / (
                SELECT COUNT(*) FROM `{$table}`
                WHERE `{$dateColumn}` >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ), 2) as percentage", [$days])
            ->whereRaw("`{$dateColumn}` >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$days])
            ->groupByRaw("HOUR(`{$dateColumn}`)")
            ->orderBy('count', 'DESC')
            ->get() ?? [];
    }

    public function getPeriodStatsData(
        string $table,
        string $dateColumn,
        int $offsetDays,
        int $periodDays,
        array $conditions = []
    ): array {
        $this->validateTableName($table);
        $this->validateColumnName($dateColumn);

        $query = $this->db->table($table)
            ->selectRaw('COUNT(*) as total')
            ->whereRaw("`{$dateColumn}` >= DATE_SUB(NOW(), INTERVAL ? DAY)", [$offsetDays + $periodDays])
            ->whereRaw("`{$dateColumn}` < DATE_SUB(NOW(), INTERVAL ? DAY)", [$offsetDays]);

        foreach ($conditions as $column => $value) {
            $this->validateColumnName($column);
            $query->where($column, '=', $value);
        }

        $result = $query->first();

        return [
            'total' => (int)($result->total ?? 0),
            'period_days' => $periodDays,
        ];
    }
}
