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
            $this->db->query(
                "INSERT INTO performance_logs 
                (endpoint, method, execution_time, memory_usage, status_code, 
                 user_id, ip_address, is_slow, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
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
