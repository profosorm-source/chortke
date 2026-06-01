<?php

declare(strict_types=1);

namespace App\Services\Sentry\Analytics;

use App\Models\SentryModel;

/**
 * 📈 TrendAnalyzer - تحلیل روندها و پیش‌بینی
 */
class TrendAnalyzer
{
    private SentryModel $model;

    public function __construct(SentryModel $model)
    {
        $this->model = $model;
    }

    /**
     * 📊 Analyze Trends
     */
    public function analyzeTrends(string $metric, int $days = 7): array
    {
        $data = $this->getHistoricalData($metric, $days);
        
        return [
            'trend' => $this->calculateTrend($data),
            'anomalies' => $this->detectAnomalies($data),
            'forecast' => $this->forecast($data, 3),
            'patterns' => $this->detectPatterns($data),
        ];
    }

    private function calculateTrend(array $data): array
    {
        if (count($data) < 2) return ['direction' => 'stable', 'strength' => 0, 'slope' => 0.0];

        $n = count($data);
        $sumX = $sumY = $sumXY = $sumX2 = 0;

        foreach ($data as $i => $point) {
            $x = $i;
            $y = $point['value'];
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumX2 += $x * $x;
        }

        // AN4: Defensively verify denominator bounds to defeat Division by Zero runtime panics
        $denominator = ($n * $sumX2 - $sumX * $sumX);
        $slope = abs($denominator) > 0.000001 ? ($n * $sumXY - $sumX * $sumY) / $denominator : 0.0;
        
        return [
            'direction' => $slope > 0.1 ? 'increasing' : ($slope < -0.1 ? 'decreasing' : 'stable'),
            'strength' => abs($slope),
            'slope' => $slope,
        ];
    }

    private function detectAnomalies(array $data): array
    {
        if (count($data) < 3) return [];

        $values = array_column($data, 'value');
        $mean = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $value) $variance += pow($value - $mean, 2);
        
        $stdDev = sqrt($variance / count($values)) ?: 1;
        $threshold = 2;

        $anomalies = [];
        foreach ($data as $point) {
            $zScore = abs(($point['value'] - $mean) / $stdDev);
            if ($zScore > $threshold) {
                $anomalies[] = [
                    'timestamp' => $point['timestamp'],
                    'value' => $point['value'],
                    'z_score' => round($zScore, 2),
                    'severity' => $zScore > 3 ? 'high' : 'medium',
                ];
            }
        }
        return $anomalies;
    }

    private function forecast(array $data, int $periods): array
    {
        if (count($data) < 3) return [];

        $windowSize = min(7, count($data));
        $recentValues = array_slice(array_column($data, 'value'), -$windowSize);
        $average = array_sum($recentValues) / count($recentValues);

        $trend = $this->calculateTrend($data);
        $forecasts = [];
        $lastTimestamp = strtotime((string)$data[count($data) - 1]['timestamp']);

        for ($i = 1; $i <= $periods; $i++) {
            $forecastValue = $average + ($trend['slope'] * $i);
            $forecasts[] = [
                'timestamp' => date('Y-m-d H:i:s', strtotime("+{$i} day", $lastTimestamp)),
                'value' => max(0, round($forecastValue, 2)),
                'confidence' => $this->calculateConfidence($data),
            ];
        }
        return $forecasts;
    }

    private function detectPatterns(array $data): array
    {
        $patterns = [];
        $spikes = $this->detectSpikes($data);
        if (!empty($spikes)) {
            $patterns[] = ['type' => 'spike', 'description' => count($spikes) . ' spike(s) detected', 'occurrences' => $spikes];
        }

        $cyclical = $this->detectCyclicalPattern($data);
        if ($cyclical) {
            $patterns[] = ['type' => 'cyclical', 'description' => 'Cyclical pattern detected', 'period' => $cyclical['period']];
        }

        $trend = $this->calculateTrend($data);
        if ($trend['direction'] !== 'stable') {
            $patterns[] = ['type' => 'gradual_' . $trend['direction'], 'description' => "Gradual {$trend['direction']}", 'strength' => $trend['strength']];
        }

        return $patterns;
    }

    private function detectSpikes(array $data): array
    {
        if (count($data) < 3) return [];
        $spikes = [];
        for ($i = 1; $i < count($data) - 1; $i++) {
            $prev = $data[$i - 1]['value'];
            $current = $data[$i]['value'];
            $next = $data[$i + 1]['value'];
            if ($current > ($prev * 2) && $current > ($next * 2)) {
                $spikes[] = ['timestamp' => $data[$i]['timestamp'], 'value' => $current];
            }
        }
        return $spikes;
    }

    private function detectCyclicalPattern(array $data): ?array
    {
        if (count($data) < 14) return null;
        $values = array_column($data, 'value');
        $period = 7; $correlation = 0; $count = 0;

        for ($i = 0; $i < count($values) - $period; $i++) {
            if (abs($values[$i] - $values[$i + $period]) < $values[$i] * 0.3) $correlation++;
            $count++;
        }
        $score = $count > 0 ? $correlation / $count : 0;
        return $score > 0.6 ? ['period' => $period, 'confidence' => $score] : null;
    }

    private function getHistoricalData(string $metric, int $days): array
    {
        if ($metric === 'errors') {
            $data = $this->model->getErrorHistoricalData($days);
            return array_map(fn($item) => ['timestamp' => $item->date, 'value' => (int)$item->value], $data);
        } elseif ($metric === 'performance') {
            $data = $this->model->getPerformanceHistoricalData($days);
            return array_map(fn($item) => ['timestamp' => $item->date, 'value' => round((float)($item->value ?? 0), 2)], $data);
        }
        return [];
    }

    private function calculateConfidence(array $data): float
    {
        $dataPoints = count($data);
        $values = array_column($data, 'value');
        if (count($values) < 2) return 0.5;

        $mean = array_sum($values) / count($values);
        $variance = 0;
        foreach ($values as $value) $variance += pow($value - $mean, 2);
        
        $cv = $mean > 0 ? sqrt($variance / count($values)) / $mean : 1;
        $confidence = min(1, ($dataPoints / 30) * (1 - min(1, (float)$cv)));
        
        return round($confidence, 2);
    }

    public function getErrorHotspots(): array
    {
        return $this->model->getErrorHotspots(7);
    }

    public function getPerformanceDegradation(): array
    {
        $thisWeekAvg = $this->model->getWeeklyPerformanceAvg(0);
        $lastWeekAvg = $this->model->getWeeklyPerformanceAvg(7);

        $change = $lastWeekAvg > 0 ? (($thisWeekAvg - $lastWeekAvg) / $lastWeekAvg) * 100 : 0;

        return [
            'this_week_avg' => round($thisWeekAvg, 2),
            'last_week_avg' => round($lastWeekAvg, 2),
            'change_percent' => round($change, 2),
            'status' => $change > 10 ? 'degraded' : ($change < -10 ? 'improved' : 'stable'),
        ];
    }
}
