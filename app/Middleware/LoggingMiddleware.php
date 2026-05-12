<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Queue;
use Core\Request;
use Core\Response;
use Core\Session;
use Closure;
use App\Contracts\LoggerInterface;

/**
 * LoggingMiddleware — ثبت لاگ عملکرد و خطاهای درخواست‌ها به صورت آسنکرون و غیرمسدودکننده (Non-blocking)
 */
class LoggingMiddleware extends BaseMiddleware
{
    private LoggerInterface $logger;
    private Queue $queue;
    private Session $session;
    private static float $startTime;
    private static int $startMemory;

    /**
     * تزریق خودکار وابستگی‌ها (تزریق صف به جای دیتابیس مستقیم)
     */
    public function __construct(LoggerInterface $logger, Queue $queue, Session $session)
    {
        $this->logger = $logger;
        $this->queue = $queue;
        $this->session = $session;
    }

    /**
     * اجرای Middleware
     */
    public function handle(Request $request, Closure $next): Response
    {
        self::$startTime = microtime(true);
        self::$startMemory = memory_get_usage();

        try {
            $response = $next($request);
            
            // کپسوله‌سازی پاسخ با متد به اشتراک‌گذاری شده لایه پایه
            $response = $this->toResponse($response);

            $this->logPerformance($request, $response->getStatusCode());
            
            return $response;

        } catch (\Throwable $e) {
            $this->logPerformance($request, 500);
            throw $e;
        }
    }

    /**
     * ارسال لاگ پرفورمنس به صف پس‌زمینه (کاملاً غیرمسدودکننده)
     */
    private function logPerformance(Request $request, int $statusCode): void
    {
        try {
            if (!config('logging.performance.log_performance', true)) {
                return;
            }

            $executionTime = (microtime(true) - self::$startTime) * 1000; // ms
            $memoryUsage = memory_get_usage() - self::$startMemory;
            
            $slowThreshold = (float)config('logging.performance.performance_threshold_ms', 500); // 500ms
            $isSlow = $executionTime > $slowThreshold;

            $userId = $this->session->get('user_id');

            // ارسال هوشمند به صف دیتابیس به جای کوئری مسدودکننده مستقیم دیتابیس
            $this->queue->push(\App\Jobs\LogPerformanceJob::class, [
                'endpoint'       => $request->uri(),
                'method'         => $request->method(),
                'execution_time' => $executionTime,
                'memory_usage'   => $memoryUsage,
                'status_code'    => $statusCode,
                'user_id'        => $userId,
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
                'is_slow'        => $isSlow ? 1 : 0
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('middleware.performance_logging.failed', [
                'channel' => 'middleware',
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        }
    }
}
