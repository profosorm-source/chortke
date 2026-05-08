<?php

namespace App\Services;

use Core\Database;

use App\Contracts\LoggerInterface;
/**
 * سرویس مانیتورینگ عملکرد سیستم
 */
class PerformanceMonitorService extends \App\Services\BaseService
{
    private Database $db;
    private float $startTime;
    private int $startMemory;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage();
    }

    /**
     * ثبت اطلاعات عملکرد درخواست
     */
    public function logRequest(
        string $endpoint,
        string $method,
        int $statusCode,
        ?int $userId = null
    ): void {
        $executionTime = (microtime(true) - $this->startTime) * 1000; // میلی‌ثانیه
        $memoryUsage = memory_get_usage() - $this->startMemory;
        
        // آیا کند بوده؟ (بیشتر از 1 ثانیه)
        $isSlow = $executionTime > 1000;

        try {
            $this->db->query(
                "INSERT INTO performance_logs 
                (endpoint, method, execution_time, memory_usage, status_code, 
                 user_id, ip_address, is_slow)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $endpoint,
                    $method,
                    $executionTime,
                    $memoryUsage,
                    $statusCode,
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $isSlow ? 1 : 0
                ]
            );

            // اگر خیلی کند بود، هشدار بده
            if ($executionTime > 3000) { // بیشتر از 3 ثانیه
                $this->createSlowRequestAlert($endpoint, $executionTime);
            }

        } catch (\Throwable $e) {
            $this->logger->error('performance_monitor.log.failed', [
    'channel' => 'performance',
    'error' => $e->getMessage(),
]);
        }
    }

    /**
     * ایجاد هشدار برای درخواست کند
     */
    private function createSlowRequestAlert(string $endpoint, float $time): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_alerts 
                (alert_type, severity, title, message, metadata, current_value)
                VALUES ('performance', 'medium', ?, ?, ?, ?)",
                [
                    'درخواست کند',
                    "Endpoint: {$endpoint} با " . round($time) . " میلی‌ثانیه",
                    json_encode(['endpoint' => $endpoint, 'time' => $time]),
                    $time
                ]
            );
        } catch (\Throwable $e) {
            // Silent
        }
    }

    /**
     * دریافت آمار عملکرد
     */
    public function getStatistics(string $period = 'today'): array
    {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'yesterday' => "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        // میانگین زمان اجرا
        $avgTime = $this->db->query(
            "SELECT AVG(execution_time) as avg_time,
                    MIN(execution_time) as min_time,
                    MAX(execution_time) as max_time
             FROM performance_logs 
             WHERE {$dateCondition}"
        )->fetch(\PDO::FETCH_OBJ);

        // تعداد درخواست‌های کند
        $slowCount = $this->db->query(
            "SELECT COUNT(*) as count FROM performance_logs 
             WHERE is_slow = 1 AND {$dateCondition}"
        )->fetch(\PDO::FETCH_OBJ);

        // کندترین endpointها
        $slowest = $this->db->query(
            "SELECT endpoint, AVG(execution_time) as avg_time, COUNT(*) as count
             FROM performance_logs 
             WHERE {$dateCondition}
             GROUP BY endpoint 
             ORDER BY avg_time DESC 
             LIMIT 10"
        )->fetchAll(\PDO::FETCH_OBJ);

        // آمار به ازای ساعت
        $hourly = $this->db->query(
            "SELECT 
                HOUR(created_at) as hour,
                COUNT(*) as requests,
                AVG(execution_time) as avg_time,
                SUM(CASE WHEN is_slow = 1 THEN 1 ELSE 0 END) as slow_count
             FROM performance_logs 
             WHERE {$dateCondition}
             GROUP BY HOUR(created_at)
             ORDER BY hour"
        )->fetchAll(\PDO::FETCH_OBJ);

        return [
            'avg_time' => round($avgTime->avg_time ?? 0, 2),
            'min_time' => round($avgTime->min_time ?? 0, 2),
            'max_time' => round($avgTime->max_time ?? 0, 2),
            'slow_count' => $slowCount->count ?? 0,
            'slowest_endpoints' => $slowest,
            'hourly_stats' => $hourly
        ];
    }

    /**
     * پیش‌بینی مشکلات عملکردی
     */
    public function predictIssues(): array
    {
        $predictions = [];

        // بررسی روند افزایش زمان اجرا
        $trend = $this->db->query(
            "SELECT 
                DATE(created_at) as date,
                AVG(execution_time) as avg_time
             FROM performance_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date"
        )->fetchAll(\PDO::FETCH_OBJ);

        if (count($trend) >= 3) {
            $times = array_map(fn($t) => $t->avg_time, $trend);
            $lastThree = array_slice($times, -3);
            
            // اگر هر روز کندتر شده
            if ($lastThree[0] < $lastThree[1] && $lastThree[1] < $lastThree[2]) {
                $predictions[] = [
                    'type' => 'performance_degradation',
                    'severity' => 'medium',
                    'message' => 'روند کاهش عملکرد در 3 روز اخیر مشاهده شده',
                    'data' => $lastThree
                ];
            }
        }

        // بررسی مصرف حافظه
        $memoryTrend = $this->db->query(
            "SELECT AVG(memory_usage) as avg_memory
             FROM performance_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        )->fetch(\PDO::FETCH_OBJ);

        if ($memoryTrend && $memoryTrend->avg_memory > 100 * 1024 * 1024) { // 100MB
            $predictions[] = [
                'type' => 'high_memory_usage',
                'severity' => 'high',
                'message' => 'مصرف حافظه بالاست: ' . round($memoryTrend->avg_memory / 1024 / 1024, 2) . ' MB',
                'data' => ['memory' => $memoryTrend->avg_memory]
            ];
        }

        return $predictions;
    }

    /**
     * بهینه‌سازی خودکار
     */
    public function getOptimizationSuggestions(): array
    {
        $suggestions = [];

        // Endpointهایی که Query زیاد دارن
        $queryHeavy = $this->db->query(
            "SELECT endpoint, AVG(query_count) as avg_queries
             FROM performance_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             AND query_count > 0
             GROUP BY endpoint 
             HAVING avg_queries > 20
             ORDER BY avg_queries DESC"
        )->fetchAll(\PDO::FETCH_OBJ);

        foreach ($queryHeavy as $ep) {
            $suggestions[] = [
                'endpoint' => $ep->endpoint,
                'issue' => 'تعداد Query زیاد',
                'suggestion' => 'استفاده از Eager Loading یا Cache',
                'details' => "میانگین {$ep->avg_queries} query در هر درخواست"
            ];
        }

        // Endpointهای همیشه کند
        $alwaysSlow = $this->db->query(
            "SELECT endpoint, AVG(execution_time) as avg_time, COUNT(*) as count
             FROM performance_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY endpoint 
             HAVING avg_time > 2000
             ORDER BY avg_time DESC"
        )->fetchAll(\PDO::FETCH_OBJ);

        foreach ($alwaysSlow as $ep) {
            $suggestions[] = [
                'endpoint' => $ep->endpoint,
                'issue' => 'همیشه کند است',
                'suggestion' => 'نیاز به بهینه‌سازی کد یا استفاده از Cache',
                'details' => "میانگین " . round($ep->avg_time) . " میلی‌ثانیه"
            ];
        }

        return $suggestions;
    }
}

