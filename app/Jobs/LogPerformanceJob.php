<?php

declare(strict_types=1);

namespace App\Jobs;

use Core\Database;

/**
 * LogPerformanceJob — ثبت لاگ عملکرد برنامه‌ به صورت پس‌زمینه از طریق صف (Queue)
 */
class LogPerformanceJob
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * اجرای تسک ذخیره‌سازی لاگ پرفورمنس
     */
    public function handle(array $data): void
    {
        try {
            $supportsDetailedSchema = (bool) $this->db->query("SHOW COLUMNS FROM performance_logs LIKE 'endpoint'")->fetch();

            if ($supportsDetailedSchema) {
                $this->db->query(
                    "INSERT INTO performance_logs 
                    (request_id, metric, value, context, endpoint, method, execution_time, memory_usage, status_code, 
                     user_id, ip_address, is_slow, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                    [
                        $data['request_id'] ?? $_SERVER['REQUEST_ID'] ?? null,
                        'request_duration_ms',
                        $data['execution_time'] ?? 0,
                        json_encode([
                            'ip_address' => $data['ip_address'] ?? null,
                            'is_slow' => $data['is_slow'] ?? 0,
                        ], JSON_UNESCAPED_UNICODE),
                        $data['endpoint'] ?? '',
                        $data['method'] ?? '',
                        $data['execution_time'] ?? 0,
                        $data['memory_usage'] ?? 0,
                        $data['status_code'] ?? 200,
                        $data['user_id'] ?? null,
                        $data['ip_address'] ?? null,
                        $data['is_slow'] ?? 0
                    ]
                );
            } else {
                $this->db->query(
                    "INSERT INTO performance_logs 
                    (metric, value, context, created_at)
                    VALUES (?, ?, ?, NOW())",
                    [
                        'request_duration_ms',
                        $data['execution_time'] ?? 0,
                        json_encode([
                            'endpoint' => $data['endpoint'] ?? null,
                            'method' => $data['method'] ?? null,
                            'status_code' => $data['status_code'] ?? 200,
                            'ip_address' => $data['ip_address'] ?? null,
                            'is_slow' => $data['is_slow'] ?? 0,
                        ], JSON_UNESCAPED_UNICODE)
                    ]
                );
            }
        } catch (\Throwable $e) {
            // در صورت بروز خطا در درایور پس‌زمینه، خطا در سیستم لاگر ثبت می‌شود
            if (function_exists('logger')) {
                logger()->error('jobs.log_performance.failed', [
                    'channel' => 'queue',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        }
    }
}
