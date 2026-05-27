<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Container;
use Core\EventDispatcher;
use Core\Queue;

/**
 * QueueWorker - executes queued jobs and centralizes retry/DLQ handling.
 *
 * Core\Queue فقط storage abstraction باقی می‌ماند؛ اجرای Jobها اینجا انجام می‌شود.
 */
class QueueWorker extends BaseService
{
    private bool $shouldQuit = false;

    public function __construct(
        private Queue $queue,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
        $this->registerGracefulShutdownHandler();
    }

    private function registerGracefulShutdownHandler(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $handler = function ($signo) {
                $this->logger->info('queue_worker.graceful_shutdown_initiated', ['signal' => $signo]);
                $this->shouldQuit = true;
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGQUIT, $handler);
        }
    }

    public function work(?string $queueName = null, int $limit = 10, ?array $allowedJobs = null): array
    {
        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $limit = max(1, min(500, $limit));
        $allowedJobs ??= $this->defaultAllowedJobs();

        for ($i = 0; $i < $limit; $i++) {
            if ($this->shouldQuit) {
                $this->logger->info('queue_worker.stopped_gracefully', ['processed' => $processed]);
                break;
            }

            $job = $this->queue->pop($queueName);
            if (!$job) {
                break; // No more jobs
            }

            try {
                // اجرای خطی و همگام جاب‌ها - مقیاس‌پذیری افقی (Horizontal Scaling) 
                // باید از طریق اجرای چندین Worker به صورت موازی (مثلاً با Supervisor) انجام شود.
                // Record attempt for the system-wide retry budget tracking
                \Core\RetryPolicy::recordAttempt();
                $this->handleJob($job, $allowedJobs);
                $this->queue->delete((int) $job['id']);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->handleFailure($job, $e);
            } finally {
                $this->performMemoryCleanup();
            }
        }

        return [
            'processed_jobs' => $processed,
            'failed_jobs' => $failed,
            'skipped_jobs' => $skipped,
        ];
    }

    private function handleJob(array $job, array $allowedJobs): void
    {
        $jobClass = (string) ($job['job'] ?? '');
        $data = (array) ($job['data'] ?? []);

        Container::resetTraceStack();

        $correlationId = $job['meta']['correlation_id'] ?? null;
        $traceId = $job['meta']['trace_id'] ?? null;
        
        if ($traceId) {
            $_SERVER['HTTP_X_TRACE_ID'] = $traceId; // Propagate distributed trace context
        }

        if ($correlationId && method_exists($this->logger, 'withContext')) {
            $this->logger->withContext([
                'correlation_id' => $correlationId,
                'trace_id' => $traceId,
                'queue_job_id' => $job['id'] ?? null,
            ]);
        }

        if ($jobClass === 'dispatch_event') {
            $dispatcher = Container::getInstance()->make(EventDispatcher::class);
            $dispatcher->processQueuedEvent($job);
            return;
        }

        if (!in_array($jobClass, $allowedJobs, true)) {
            throw new \RuntimeException("Queue job not allowed: {$jobClass}");
        }

        if (!class_exists($jobClass)) {
            throw new \RuntimeException("Queue job not found: {$jobClass}");
        }

        $handler = Container::getInstance()->make($jobClass);

        if (!method_exists($handler, 'handle')) {
            throw new \RuntimeException("Queue job has no handle method: {$jobClass}");
        }

        $handler->handle($data);
    }

    private function handleFailure(array $job, \Throwable $e): void
    {
        $attempts = (int) ($job['attempts'] ?? 0);
        $jobClass = (string) ($job['job'] ?? '');

        $this->logger->error('queue_job_failed', [
            'job_id' => $job['id'] ?? null,
            'job' => $jobClass,
            'attempts' => $attempts,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => config('app.debug') ? substr($e->getTraceAsString(), 0, 2048) : null,
        ]);

        try {
            if ($attempts >= $this->queue->getMaxAttempts()) {
                $this->queue->fail((int) $job['id'], $e);
                $this->logger->warning('queue_job_sent_to_dlq', [
                    'job_id' => $job['id'] ?? null,
                    'job' => $jobClass,
                ]);

                // Sentry Integration: Capture DLQ failures for monitoring/alerting
                try {
                    \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, [
                        'queue_job_id' => $job['id'] ?? null,
                        'queue_job_class' => $jobClass,
                        'attempts' => $attempts,
                    ]);
                } catch (\Throwable $sentryError) {
                    // Fail silently for sentry
                }
                return;
            }

            // System-wide Retry Budget check to prevent Queue Retry Storm
            if (!\Core\RetryPolicy::acquireRetryBudget()) {
                $this->logger->critical('queue_job_aborted_budget', [
                    'job_id' => $job['id'] ?? null,
                    'job' => $jobClass,
                ]);
                $this->queue->fail((int) $job['id'], new \RuntimeException("Queue Retry Storm protection: system-wide retry budget exhausted. " . $e->getMessage(), 503, $e));
                return;
            }

            // Calculate customized retry delay if defined on the job handler
            $delay = 0;
            if (class_exists($jobClass)) {
                try {
                    $handler = Container::getInstance()->make($jobClass);
                    if (method_exists($handler, 'retryAfter')) {
                        $delay = (int) $handler->retryAfter($attempts);
                    } elseif (property_exists($handler, 'backoff')) {
                        $delay = (int) $handler->backoff;
                    }
                } catch (\Throwable $inspectErr) {
                    // Fail-safe to default exponential delay if instantiation fails
                }
            }

            $this->queue->release((int) $job['id'], $delay);
            $this->logger->warning('queue_job_released_retry', [
                'job_id' => $job['id'] ?? null,
                'job' => $jobClass,
                'attempts' => $attempts,
                'delay_applied' => $delay,
            ]);
        } catch (\Throwable $failError) {
            $this->logger->critical('queue_failure_handler_failed', [
                'job_id' => $job['id'] ?? null,
                'job' => $jobClass,
                'error' => $failError->getMessage(),
            ]);
        }
    }

    private function defaultAllowedJobs(): array
    {
        return config('queue.allowed_jobs') ?? [
            \App\Jobs\ApplyWeeklyProfitLossJob::class,
            \App\Jobs\LogPerformanceJob::class,
            \App\Jobs\SendBulkNotificationJob::class,
            \App\Jobs\PersistBulkInAppNotificationJob::class,
            \App\Jobs\SendEmailJob::class,
            \App\Jobs\UpdateFraudScoreJob::class,
            \App\Jobs\InvestmentProfitDistributionJob::class,
            \App\Jobs\NotificationCleanupJob::class,
            \App\Jobs\EscrowTimeoutJob::class,
            \App\Jobs\CacheWarmupJob::class,
            \App\Jobs\ScoreRecalculationJob::class,
            \App\Jobs\PredictionGameSettlementJob::class,
            \App\Jobs\VitrineListingExpiryJob::class,
            \App\Jobs\InfluencerOrderTimeoutJob::class,
            \App\Jobs\SocialTaskApprovalReminderJob::class,
            \App\Jobs\AggregateAnalyticsJob::class,
            \App\Jobs\RunCronTaskJob::class,
        ];
    }

    /**
     * پاکسازی کامل حافظه پس از اجرای هر جاب در پروسه‌های طولانی (Long-running Queue Workers)
     */
    private function performMemoryCleanup(): void
    {
        $container = Container::getInstance();

        // ۱. پاکسازی آبجکت‌های Scoped
        if (method_exists($container, 'flushScoped')) {
            $container->flushScoped();
        }

        // ۲. پاکسازی نمونه‌های سینگلتون اضافی برای ممانعت از نشت حافظه
        if (method_exists($container, 'flushSingletonInstances')) {
            $container->flushSingletonInstances();
        }

        // ۳. پاکسازی کش رفلکشن کانتینر
        if (method_exists($container, 'flushReflectionCache')) {
            $container->flushReflectionCache();
        }

        // ۴. بازگردانی وضعیت شنونده‌های رویداد به حالت اولیه بوت‌استرپ
        if ($container->has(EventDispatcher::class)) {
            try {
                $dispatcher = $container->make(EventDispatcher::class);
                if (method_exists($dispatcher, 'restoreBootstrapState')) {
                    $dispatcher->restoreBootstrapState();
                }
            } catch (\Throwable $ignored) {}
        }

        // ۵. پاکسازی قفل‌های بجامانده در سیستم کش
        if ($container->has(\Core\Cache::class)) {
            try {
                $cache = $container->make(\Core\Cache::class);
                if (method_exists($cache, 'flushAllLocks')) {
                    $cache->flushAllLocks();
                }
            } catch (\Throwable $ignored) {}
        }

        // ۶. اجرای رفتگر برای بازپس‌گیری حافظه‌های چرخه‌ای
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }

        // ۷. تازه‌سازی پیکربندی‌ها برای 반영 تغییرات feature flag یا config
        if (function_exists('config_reload')) {
            config_reload();
        }
    }
}
